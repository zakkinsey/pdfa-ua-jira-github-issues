<?php
/**
 * Github to Jira Migration
 *
 * Step 2: Export all tickets from Jira into JSON file(s) on disk.
 *
 * We don't want to require both Jira and Github uptime, so we use an intermediate
 * format for all issues, where we export Jira issues into the format that the Github
 * bulk import API needs. This script is written in a way so that it can be "continued"
 * after abort.
 *
 * @example
 *  $ php export_jira_tickets <Project> <StartAt>
 */

require_once 'vendor/autoload.php';
require_once 'jira_markdown.php';

$dotenv = new Dotenv\Dotenv(__DIR__);
$dotenv->load();

if (!isset($argv[1])) {
    printf("Missing argument: Project Key\n");
    exit(1);
}

$project = $argv[1];
$projects = require 'projects.php';

if (!isset($projects[$project])) {
    printf("Unknown project: $project\n");
    exit(2);
}

$count = 0;

@mkdir("data/" . $project, 0777);

$knownIssueTypes = explode(',', getenv('ISSUE_TYPES'));
$knownAssigneesMap = json_decode(getenv('ASSIGNEES'), true);

$dataDir = "data";
$projectDataTag = "$dataDir/$project";
$jiraExportDir = "$projectDataTag/jira-export";
$j2mDir = "$projectDataTag/j2m";

@mkdir(                        $j2mDir,  0777);
@mkdir(getDirTxtJiraFormat(    $j2mDir), 0777);
@mkdir(getDirTxtGithubMarkdown($j2mDir), 0777);

$usersMap = [];
$usersTSV = file_get_contents("$dataDir/users.tsv");
foreach (preg_split('/\n/', $usersTSV, -1, PREG_SPLIT_NO_EMPTY) as $userTsvLine) {
    $tsvValues = preg_split('/\t/', $userTsvLine);
    $usersMap[$tsvValues[2]] = [
        'name' => $tsvValues[0],
        'email' => $tsvValues[1],
        'githubUser' => $tsvValues[3],
    ];
}

$files = scandir($jiraExportDir);

if (isset($argv[2])) {
    $files = [$argv[2] . ".json"];
}

$issueIds = [];
foreach ($files as $file) {
    if ($file === "." || $file === "..") continue;
    $issueId = preg_replace("/jira-$project-(\\d+)\\.json/", '$1', $file);
    array_push($issueIds, intval($issueId));
}
sort($issueIds);

foreach ($issueIds as $issueId) {
    $issueKey = $project . '-' . $issueId;
    $file = $issueKey . '.json';
    $issue = json_decode(file_get_contents("$jiraExportDir/jira-$issueKey.json"), true);

    //print_r($issue);
    $import = [
        'jiraKey' => $issueKey,
        'issue' => [
            'title' => sprintf('%s: %s', $issueKey, $issue['fields']['summary']),
            'body' => sprintf(
                "Jira issue originally created by user %s:\n\n%s",
                mentionName($usersMap, $issue['fields']['creator']),
                exportAndMarkdown($j2mDir, "$issueKey.txt", $issue['fields']['description'])
            ),
            'created_at' => fixTimestamp($issue['fields']['created']),
            'closed' => in_array($issue['fields']['status']['name'], explode(',', getenv('CLOSED_STATES'))),
        ],
    ];

    if (isset($issue['fields']['issuetype']['name']) && in_array($issue['fields']['issuetype']['name'], $knownIssueTypes)) {
        $import['issue']['labels'] = [$issue['fields']['issuetype']['name']];
    }

    if (isset($issue['fields']['fixVersions']) && count($issue['fields']['fixVersions']) > 0) {
        $milestoneVersion = array_reduce($issue['fields']['fixVersions'], function ($last, $version) {
            $versionName = preg_replace('(^v)', '', $version['name']);
            if (version_compare($last, $versionName) > 0) {
                return $versionName;
            }
            return $last;
        }, '10.0.0');

        if (isset($existingMilestones[$milestoneVersion])) {
            $import['issue']['milestone'] = $existingMilestones[$milestoneVersion];
        }
    }

    if (isset($issue['fields']['customfield_10302'])) {
        $testsText = $issue['fields']['customfield_10302'];
        exportAndMarkdown($j2mDir, "$issueKey-Tests.txt", $testsText);
    }

    $import['comments'] = [];

    if (isset($issue['fields']['comment']) && count($issue['fields']['comment']['comments']) > 0) {
        $commentsLastIndex = count($issue['fields']['comment']['comments']) - 1;
        $commentIndexesLength = strlen("$commentsLastIndex");
        foreach ($issue['fields']['comment']['comments'] as $commentIndex => $comment) {
            $commentFile = sprintf("$issueKey-comment-%0{$commentIndexesLength}d.txt", $commentIndex);
            $import['comments'][] = [
                'created_at' => fixTimestamp($comment['created']),
                'body' => exportAndMarkdown($j2mDir, $commentFile, sprintf(
                    "Comment created by %s:\n\n%s",
                    mentionName($usersMap, $comment['author']),
                    $comment['body']
                )),
            ];
        }
    }

    // add comments for assignee and link changes from changelog
    if (isset($issue['changelog']) && count($issue['changelog']['histories']) > 0) {
        foreach ($issue['changelog']['histories'] as $historyEntry) {
            $timestamp = fixTimestamp($historyEntry['created']);
            $name = mentionName($usersMap, $historyEntry['author']);
            $id = $historyEntry['id'];
            foreach ($historyEntry['items'] as $historyItem) {
                $historyTextFile = "$issueKey-history-$id.txt";
                $from       = $historyItem['from'];
                $fromString = $historyItem['fromString'];
                $to         = $historyItem['to'];
                $toString   = $historyItem['toString'];
                $body = null;
                if ($historyItem['field'] == 'Link') {
                    if ($from == null) {
                        $body = "$name added a link to $to: $toString";
                    }
                    if ($to == null) {
                        $body = "$name removed a link to $from: $fromString";
                    }
                    if ($from != null && $to != null) {
                        // no evidence this occurs for links
                        $body = "$name changed a link from $from to $to: $toString";
                    }
                }
                if ($historyItem['field'] == 'assignee') {
                    if ($from == null) {
                        $body = "$name assigned issue to " . mentionAssignee($usersMap, $name, $to);
                    }
                    if ($to == null) {
                        $body = "$name unassigned issue from " . mentionAssignee($usersMap, $name, $from);
                    }
                    if ($from != null && $to != null) {
                        $body = "$name changed assignee from " . mentionAssignee($usersMap, $name, $from) .  " to " . mentionAssignee($usersMap, $name, $to);
                    }
                }
                if ($body != null) {
                    $import['comments'][] = [
                        'created_at' => $timestamp,
                        'body' => exportAndMarkdown($j2mDir, $historyTextFile, $body),
                    ];
                }
            }
        }
    }

    if (isset($issue['fields']['assignee']) && $issue['fields']['assignee']) {
        $dateTime = gmdate(DateTimeInterface::ISO8601);
        $name = mentionName($usersMap, 'zak.kinsey@targetstream.com');
        $from = $issue['fields']['assignee'];
        $body = "$name unassigned issue from " . mentionAssignee($usersMap, $name, $from) . ' as part of jira->github migration';
        $import['comments'][] = [
            'created_at' => preg_replace('/[+]0000/', 'Z', $dateTime),
            'body' => exportAndMarkdown($j2mDir, "$issueKey-final-unassign.txt", $body),
        ];
    }

    usort($import['comments'], function ($a, $b) {
        $aTime = strtotime($a['created_at']);
        $bTime = strtotime($b['created_at']);
        $retVal = $aTime - $bTime;
        if ($retVal == 0) {
            $retVal = $a['body'] <=> $b['body'];
        }
        return $retVal;
    });

    if (isset($issue['fields']['resolutiondate']) && $issue['fields']['resolutiondate']) {
        $import['comments'][] = [
            'created_at' => fixTimestamp($issue['fields']['resolutiondate']),
            'body' => sprintf('Issue was closed with resolution "%s"', $issue['fields']['resolution']['name']),
        ];
    }

    if (count($import['comments']) === 0) {
        unset($import['comments']);
    }

    file_put_contents("data/$project/$issueKey.json", json_encode($import, JSON_PRETTY_PRINT));
    printf("Processed issue: %s (Idx: %d)\n", $issueKey, $count);
    $count++;

}

function fixTimestamp($jiraTimestamp) {
    $localDateTime = substr($jiraTimestamp, 0, 19);
    $timeZone = substr($jiraTimestamp, 23);

    $dateTime = new DateTime($localDateTime, new DateTimeZone($timeZone));
    $dateTime->setTimeZone(new DateTimeZone('UTC'));
    $retVal = $dateTime->format(DateTimeInterface::ISO8601);
    $retVal = preg_replace('/[+]0000/', 'Z', $retVal);

    return $retVal;
}

function mentionAssignee($usersMap, $actorMention, $assigneeAuthor) {
    $assignee = mentionName($usersMap, $assigneeAuthor);
    if ($assignee == $actorMention) {
        $assignee = 'self';
    }
    return $assignee;
}

function mentionName($usersMap, $author) {
    //$line = sprintf("%s\t%s\t%s\n", $author['displayName'], $author['name'], $author['key']);
    //file_put_contents("data/users.txt", $line, FILE_APPEND);

    $mention = null;
    $userMap = null;

    if (is_array($author)) {
        if (isset($usersMap[$author['name']])) {
            $userMap = $usersMap[$author['name']];
        } else if (isset($usersMap[$author['key']])) {
            $userMap = $usersMap[$author['key']];
        }
    } else if (is_string($author)) {
        if (isset($usersMap[$author])) {
            $userMap = $usersMap[$author];
        }
    }

    if ($userMap != null) {
        if (isset($userMap['githubUser']) && strlen($userMap['githubUser']) > 0) {
            $mention = '@' . $userMap['githubUser'];
        } else if (isset($userMap['name'])) {
            if (isset($userMap['email'])) {
                $mention = $userMap['name'] . " <" . $userMap['email'] . ">";
            } else {
                $mention = $userMap['name'];
            }
        } else if (isset($userMap['email'])) {
            $mention = $userMap['email'];
        }
    }

    if ($mention == null) {
        if (is_array($author)) {
            if (isset($author['displayName'])) {
                $mention = $author['displayName'] . " (" . $author['name'] . ")";
            } else if (isset($author['name'])) {
                $mention = "JIRA user " . $author['name'];
            } else if (isset($author['name'])) {
                $mention = "JIRA user " . $author['key'];
            } else {
                $mention = "unknown JIRA user ";
            }
        } else {
            $mention = "JIRA user " . $author;
        }
    }

    return $mention;
}
