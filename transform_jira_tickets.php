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
            'title' => sprintf('%s: %s', $issue['key'], $issue['fields']['summary']),
            'body' => sprintf(
                "Jira issue originally created by user %s:\n\n%s",
                mentionName($issue['fields']['creator']['key']),
                toMarkdown($issue['fields']['description'])
            ),
            'created_at' => substr($issue['fields']['created'], 0, 19) . 'Z',
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

    $import['comments'] = [];

    if (isset($issue['fields']['comment']) && count($issue['fields']['comment']['comments']) > 0) {
        foreach ($issue['fields']['comment']['comments'] as $comment) {
            $import['comments'][] = [
                // TODO: WK this looks wrong. Replacing the time zone with 'Z', UTC+0000.
                // Probably simply need to remove the seconds fraction
                'created_at' => substr($comment['created'], 0, 19) . 'Z',
                'body' => sprintf(
                    "Comment created by %s:\n\n%s",
                    mentionName($comment['author']['key']),
                    toMarkdown($comment['body'])
                ),
            ];
        }
    }

    /*
    $import['history'] = [];
    if (isset($issue['changelog']) && count($issue['changelog']['histories']) > 0) {
        $changelog = ''
        foreach ($issue['changelog']['histories'] as $historyItem) {
            $changelog .= toMarkdown()
            $import['history'][] = [
                // TODO: WK this looks wrong. Replacing the time zone with 'Z', UTC+0000.
                // Probably simply need to remove the seconds fraction
                // copied from comments above
                'created_at' => substr($historyItem['created'], 0, 19) . 'Z',
                'body' => 'TODO: fix this',
            ];
        }
        $import['comments'][] = [
                'created_at' => substr($comment['created'], 0, 19) . 'Z',
                'body' => sprintf(
                    "Changes made to JIRA issue before import to GitHub:\n\n%s",
                    $changelog,
                ),
        ]
    }
    */

    if (isset($issue['fields']['resolutiondate']) && $issue['fields']['resolutiondate']) {
        $import['comments'][] = [
            'created_at' => substr($issue['fields']['resolutiondate'], 0, 19) . 'Z',
            'body' => sprintf('Issue was closed with resolution "%s"', $issue['fields']['resolution']['name']),
        ];
    }

    if (count($import['comments']) === 0) {
        unset($import['comments']);
    }

    file_put_contents("data/" . $project . "/" . $issue['key'] . ".json", json_encode($import, JSON_PRETTY_PRINT));
    printf("Processed issue: %s (Idx: %d)\n", $issue['key'], $count);
    $count++;

}

function mentionName($name) {
    global $knownAssigneesMap;

    if (isset($knownAssigneesMap[$name])) {
        return '@' . $knownAssigneesMap[$name];
    }
    return $name;
}
