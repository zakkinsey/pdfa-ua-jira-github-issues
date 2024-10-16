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

$attachmentsDir = "$projectDataTag/attachments/";

$gitRepoDir = getenv('gitRepoDir');

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

$fieldNamesMap = [
    "aggregateprogress"              => "Σ Progress",
    "aggregatetimeestimate"          => "Σ Remaining Estimate",
    "aggregatetimeoriginalestimate"  => "Σ Original Estimate",
    "aggregatetimespent"             => "Σ Time Spent",
    "assignee"                       => "Assignee",
    "attachment"                     => "Attachment",
    "comment"                        => "Comment",
    "components"                     => "Components",
    "created"                        => "Created",
    "creator"                        => "Creator",
    "customfield_10000"              => "Epic Link",
    "customfield_10001"              => "Epic Status",
    "customfield_10002"              => "Epic Name",
    "customfield_10003"              => "Epic Color",
    "customfield_10004"              => "Sprint",
    "customfield_10005"              => "Rank",
    "customfield_10006"              => "Story Points",
    "customfield_10100"              => "Structure Types",
    "customfield_10101"              => "Use cases",
    "customfield_10102"              => "Example type",
    "customfield_10103"              => "Reason",
    "customfield_10104"              => "AT support",
    "customfield_10106"              => "Pass / Fail",
    "customfield_10109"              => "WCAG 2.2 PDF Technique",
    "customfield_10110"              => "Matterhorn Protocol",
    "customfield_10111"              => "WCAG 2.2 Success Criteria",
    "customfield_10200"              => "Development",
    "customfield_10300"              => "Flagged",
    "customfield_10301"              => "Keywords",
    "customfield_10302"              => "Tests",
    "customfield_10400"              => "PDF/UA Parts",
    "customfield_10401"              => "UA Technique Tag",
    "customfield_10402"              => "Marked-content sequences",
    "customfield_10500"              => "Team",
    "customfield_10501"              => "Parent Link",
    "customfield_10502"              => "Target start",
    "customfield_10503"              => "Target end",
    "customfield_10504"              => "Original story points",
    "customfield_10600"              => "PAC 1 Checked",
    "customfield_10601"              => "PAC 2021 Checked",
    "customfield_10602"              => "PAC 2 Checked",
    "customfield_10603"              => "PAC 3 Checked",
    "customfield_10604"              => "Acrobat Accessibility Checked",
    "customfield_10605"              => "Arlington Checked",
    "customfield_10606"              => "CommonLook PDF Checked",
    "customfield_10607"              => "LWG Tool Checked",
    "customfield_10608"              => "veraPDF UA Checked",
    "customfield_10700"              => "BFO PDF/UA Checked",
    "customfield_10701"              => "Acrobat Preflight UA Checked",
    "customfield_10800"              => "PAC 2024 Checked",
    "description"                    => "Description",
    "duedate"                        => "Due Date",
    "environment"                    => "Environment",
    "fixVersions"                    => "Fix Version/s",
    "issuekey"                       => "Key",
    "issuelinks"                     => "Linked Issues",
    "issuetype"                      => "Issue Type",
    "labels"                         => "Labels",
    "lastViewed"                     => "Last Viewed",
    "priority"                       => "Priority",
    "progress"                       => "Progress",
    "project"                        => "Project",
    "reporter"                       => "Reporter",
    "resolution"                     => "Resolution",
    "resolutiondate"                 => "Resolved",
    "security"                       => "Security Level",
    "status"                         => "Status",
    "subtasks"                       => "Sub-Tasks",
    "summary"                        => "Summary",
    "thumbnail"                      => "Images",
    "timeestimate"                   => "Remaining Estimate",
    "timeoriginalestimate"           => "Original Estimate",
    "timespent"                      => "Time Spent",
    "timetracking"                   => "Time Tracking",
    "updated"                        => "Updated",
    "versions"                       => "Affects Version/s",
    "votes"                          => "Votes",
    "watches"                        => "Watchers",
    "worklog"                        => "Log Work",
    "workratio"                      => "Work Ratio",
];

$statusMap = [
    'Reported' => 'Reported',
    'Normalization' => 'Normalization',
    'Tag Ready' => 'Tag Ready',
    'Done' => 'Done',
    'Deliberation' => 'Deliberation',
    'Postponed' => 'Postponed',
    'Not accepted' => 'Not Accepted',
    'Standardization' => 'Standardization',
    'READY FOR PEER REVIEW' => 'Ready For Peer Review',
    'REFINE' => 'Refine',
    'READY FOR GROUP REVIEW' => 'Ready For Group Review',
    'Fix Metadata' => 'Fix Metadata',
    'Metadata Review' => 'Metadata Review',
    'Accepted' => 'Accepted',
    'Web Testing' => 'Web Testing',
    'Published' => 'Published',
];

$now = substr((new DateTime())->format(DateTime::ISO8601), 0, -5) . 'Z';

$fieldRenames = [
    "2018-09-21T22:17:37Z" => [
        'william.kilian@targetstream.com' => [
            "field" => "RenameField",
            "from"  => "WCAG 2.1 SC",
            "to"    => "WCAG 2.1 Success Criteria",
        ]
    ],
    "2022-01-01T00:00:00Z" => [
        'william.kilian@targetstream.com' => [
            "field" => "RenameField",
            "from"  => "Component",
            "to"    => "Components",
        ]
    ],
    "2023-03-01T17:15:07Z" => [
        'william.kilian@targetstream.com' => [
            "field" => "RenameField",
            "from"  => "WCAG 2.1 Success Criteria",
            "to"    => "WCAG 2.2 Success Criteria",
        ]
    ],
    "2023-03-29T10:19:29Z" => [
        'william.kilian@targetstream.com' => [
            "field" => "RenameField",
            "from"  => "for PDF Technique",
            "to"    => "WCAG 2.2 PDF Technique",
        ]
    ],
    "2024-01-12T13:16:14Z" => [
        'william.kilian@targetstream.com' => [
            "field" => "RenameField",
            "from"  => "Acrobat Preflight UA Checker",
            "to"    => "Acrobat Preflight UA Checked",
        ]
    ],
    "2024-01-12T13:16:05Z" => [
        'william.kilian@targetstream.com' => [
            "field" => "RenameField",
            "from"  => "BFO PDF/UA checker",
            "to"    => "BFO PDF/UA Checked",
        ]
    ],
    $now => [
        'william.kilian@targetstream.com' => [
            "field" => "RenameField",
            "from"  => "Summary",
            "to"    => "Title",
        ]
    ],
];
$fieldRenamesReverse = array_reverse($fieldRenames);

function getFieldNameAtTime($fieldRenamesReverse, $fieldName, $unixTime) {
    foreach ($fieldRenamesReverse as $renameTimeStamp => $renameItems) {
        foreach ($renameItems as $renameItem) {
            $renameUnixTime = (new DateTimeImmutable($renameTimeStamp))->getTimestamp();
            if ($unixTime < $renameTimeStamp) {
                $renameFrom = $renameItem['from'];
                $renameTo = $renameItem['to'];
                if ($renameTo == $fieldName) {
                    $fieldName = $renameFrom;
                }
            }
        }
    }

    return $fieldName;
}

$history = [];
$fieldFinalNames = [];
$fieldAllNames = [];
foreach ($fieldRenames as $timestamp => $renameItems) {
    foreach ($renameItems as $authorName => $renameItem) {
        $old = $renameItem['from'];
        $new = $renameItem['to'];
        $fieldFinalNames[$old] = $new;
        if (!isset($fieldAllNames[$new])) {
            $fieldAllNames[$new] = [$new];
        }
        $fieldAllNames[$new][] = $old;
        for ($i = 1; $i < 300; $i++) {
            $history[$timestamp][$authorName]["PDFUA-$i"][] = $renameItem;
        }
    }
}

$fieldValueRenames = [
    '2019-02-16T00:00:00Z' => [
        'william.kilian@targetstream.com' => [
            "field" => "RenameValuesOn:Status",
            "from"  => "Input/Correction",
            "to"    => "Reported",
        ],
    ],
];

foreach ($fieldValueRenames as $timestamp => $renameItems) {
    foreach ($renameItems as $authorName => $renameItem) {
        for ($i = 1; $i < 300; $i++) {
            $history[$timestamp][$authorName]["PDFUA-$i"][] = $renameItem;
        }
    }
}

foreach ($fieldFinalNames as $fieldOrigName => $fieldFinalName) {
    while (isset($fieldFinalNames[$fieldFinalName])) {
        $fieldFinalName = $fieldFinalNames[$fieldFinalName];
        $fieldFinalNames[$fieldOrigName] = $fieldFinalName;
    }
}
foreach ($fieldFinalNames as $old => $new) {
    if (!isset($fieldAllNames[$new])) {
        $fieldAllNames[$new] = [$new];
    }
    if (!in_array($old, $fieldAllNames[$new])) {
        $fieldAllNames[$new][] = $old;
    }
}

/*
print_r([
    '$fieldRenames' => $fieldRenames,
    '$fieldAllNames' => $fieldAllNames,
    '$fieldFinalNames' => $fieldFinalNames,
]);
exit(0);
*/

$deleteFields = [
    "2023-03-29T10:38:51Z" => [
        [
            "field" => "DeleteField",
            "from"  => "PDF 2.0",
            "fromString" => "delete PDF 2.0 and Concerning replaced by PDF/UA Parts, Structure Types, Marked-content sequences, and Keywords",
        ],
        [
            "field" => "DeleteField",
            "from"  => "Concerning",
            "fromString" => "delete PDF 2.0 and Concerning replaced by PDF/UA Parts, Structure Types, Marked-content sequences, and Keywords",
        ],
    ],
];

$unhandledFields = [];
$attachments = [];
$attachmentsState = [];
$attachmentHistory = [];

foreach ($issueIds as $issueId) {
    $issueKey = $project . '-' . $issueId;
    $file = $issueKey . '.json';

    $attachments[$issueKey] = [];
    $attachmentsState[$issueKey] = [];
    $attachmentHistory[$issueKey] = [];

    $issue = json_decode(file_get_contents("$jiraExportDir/jira-$issueKey.json"), true);

    $issueCreatedTimestamp = fixTimestamp($issue['fields']['created']);
    $issueCreatedUnixTime  = (new DateTimeImmutable($issueCreatedTimestamp))->getTimestamp();
    $issueCreatorName = $issue['fields']['creator']['name'];
    $issueCreatorMention = mentionName($usersMap, $issue['fields']['creator']);

    $issueNum = 0 + $issueId;
    $issueRelativeDir = sprintf(
        "wip/ua-%02dxx/ua-%03dx/ua-%04d",
        $issueNum / 100,
        $issueNum / 10,
        $issueNum
    );

    //print_r($issue);
    if (false) {
        print_r([
            '$issueId' => $issueId,
            '$issueNum' => $issueNum,
            '$issueRelativeDir' => $issueRelativeDir,
        ]);
    }
    $import = [
        'jiraKey' => $issueKey,
        'issue' => [
            'title' => $issue['fields']['summary'],
            'body' => sprintf(
                (""
                    . "Issue directory in GitHub at time migration from Jira: [/$issueRelativeDir](../tree/main/$issueRelativeDir) \n\n"
                    . "Jira issue originally created by %s. The final version of the description at the time of migration from Jira is below. For the history of changes to the description, see `description.md` in issue directory.\n"
                    . "\n"
                    . "---\n"
                    . "%s"
                ),
                $issueCreatorMention,
                exportAndMarkdown($j2mDir, "$issueKey.txt", $issue['fields']['description'])
            ),
            'created_at' => $issueCreatedTimestamp,
            'closed' => in_array($issue['fields']['status']['name'], explode(',', getenv('CLOSED_STATES'))),
        ],
    ];

    $originalIssue = [
        'field' => 'IssueCreated',
        'attachments' => [],
        'AttachmentsFromHistory' => [],
        'Summary' => $issue['fields']['summary'],
        'Status' => $issue['fields']['status']['name'],
        'Description' => $issue['fields']['description'],
    ];

    if (isset($issue['fields']['status']['name']) && in_array($issue['fields']['status']['name'], $knownIssueTypes)) {
        $statusValue = $issue['fields']['status']['name'];
        $import['issue']['labels'] = [$issue['fields']['status']['name']];
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
        $originalIssue['Tests'] = $testsText;
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

    $issueHistory = [];

    // process history
    if (isset($issue['changelog']) && count($issue['changelog']['histories']) > 0) {
        foreach ($issue['changelog']['histories'] as $historyEntry) {
            $timestamp = fixTimestamp($historyEntry['created']);
            $author = $historyEntry['author'];
            $authorName = $author['name'];
            $name = mentionName($usersMap, $author);
            $id = $historyEntry['id'];

            if (!isset($history[$timestamp])) {
                $history[$timestamp] = [];
            }
            if (!isset($history[$timestamp][$authorName])) {
                $history[$timestamp][$authorName] = [];
            }
            if (!isset($history[$timestamp][$authorName][$issueKey])) {
                $history[$timestamp][$authorName][$issueKey] = [];
            }

            $historyItems = $historyEntry['items'];

            $history[$timestamp][$authorName][$issueKey] = array_merge(
            $history[$timestamp][$authorName][$issueKey], $historyItems);

            foreach ($historyItems as $historyItem) {
                $historyTextFile = "$issueKey-history-$id.txt";
                $fieldName = $historyItem['field'];

                if (isset($fieldNamesMap[$fieldName])) {
                    $fieldName = $fieldNamesMap[$fieldName];
                }

                $from       = $historyItem['from'];
                $fromString = $historyItem['fromString'];
                $to         = $historyItem['to'];
                $toString   = $historyItem['toString'];

                // add comments for assignee and link changes from changelog
                $body = null;
                if ($fieldName == 'Assignee') {
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
                if ($fieldName == 'Reporter') {
                    if ($from == null) {
                        $body = "$name set reporter to " . mentionAssignee($usersMap, $name, $to);
                    }
                    if ($to == null) {
                        $body = "$name removed reporter " . mentionAssignee($usersMap, $name, $from);
                    }
                    if ($from != null && $to != null) {
                        $body = "$name changed reporter from " . mentionAssignee($usersMap, $name, $from) .  " to " . mentionAssignee($usersMap, $name, $to);
                    }
                }
                if ($body != null) {
                    $import['comments'][] = [
                        'created_at' => $timestamp,
                        'body' => exportAndMarkdown($j2mDir, $historyTextFile, $body),
                    ];
                }


                if (isset($fieldFinalNames[$fieldName])) {
                    $fieldName = $fieldFinalNames[$fieldName];
                }
                if ($fieldName == 'Attachment') {
                    if ($toString != null) {
                        $fieldName = "Attachment:$to";
                    } elseif ($fromString != null) {
                        $fieldName = "Attachment:$from";
                    }
                }
                if (!isset($issueHistory[$fieldName])) {
                    $issueHistory[$fieldName] = [];
                }
                if (!isset($issueHistory[$fieldName][$timestamp])) {
                    $issueHistory[$fieldName][$timestamp] = [];
                }
                $issueHistory[$fieldName][$timestamp][] = $historyItem;
            }
        }
    }

    if (isset($issue['fields']['attachment'])) {
        foreach ($issue['fields']['attachment'] as $attachment) {
            $id = $attachment['id'];
            $a = $attachment;
            printf("%9s attachment id=%5d %8d %s %-45s '%s'\n", $issueKey, $id, $a['size'], $a['created'], mentionName($usersMap, $a['author']), $a['filename']);
            $attachments[$issueKey][$id] = $attachment;
            $attachmentHistory[$issueKey][$id] = [];
        }
    }

    if (false && $issueKey == 'PDFUA-8') {
        //print_r($attachments[$issueKey]);
        print_r($issueHistory);
        exit(0);
    }

    // process history to determine original state of all issues
    $expectMultipleItemsFields = [
        'Attachment',
        'Components',
        'Link',
    ];

    foreach ($issueHistory as $fieldName => $fieldItems) {
        $realFieldName = getFieldNameAtTime($fieldRenamesReverse, $fieldName, $issueCreatedUnixTime);

        $valuesRequireReverseReplay = [
            'Component',
            'Components'
        ];
        if (in_array($realFieldName, $valuesRequireReverseReplay)) {
            $valueIsArray =[
                'Component',
                'Components'
            ];

            // set value to final value from issue
            $value = in_array($realFieldName, $valueIsArray) ? [] : null;
            if ($realFieldName == 'Component' || $realFieldName == 'Components') {
                foreach ($issue['fields']['components'] as $valueItem) {
                    $value[$valueItem['name']] = true;
                }
            }

            // replace history in reverse to determine initial value
            krsort($fieldItems);
            $firstItem = reset($fieldItems);
            $itemsTimestamp = key($fieldItems);
            foreach ($fieldItems as $itemsTimestamp => $simultaneousFieldItems) {
                foreach($simultaneousFieldItems as $fieldItem) {
                    if (false) {
                    } elseif ($realFieldName == 'Component' || $realFieldName == 'Components') {
                        if (false) {
                        } elseif (isset($fieldItem['fromString'])) {
                            // history item removes value, so add for reverse operation
                            $value[$fieldItem['fromString']] = true;
                        } elseif (isset($fieldItem['toString'])) {
                            // history item adds value, so remove for reverse operation
                            unset($value[$fieldItem['toString']]);
                        } else {
                            printf("Weird $fieldName ($realFieldName) item at $itemsTimestamp in $issueKey:\n");
                            print_r($fieldItem);
                        }
                    }
                }
            }

            // normalize value
            if ($realFieldName == 'Component' || $realFieldName == 'Components') {
                $value = array_keys($value);
                sort($value);
            }

            $originalIssue[$realFieldName] = $value;
        }


        ksort($fieldItems);
        $firstItem = reset($fieldItems);
        $itemsTimestamp = key($fieldItems);

        if (!in_array($fieldName, $expectMultipleItemsFields)) {
            if (count($firstItem) == 1) {
                $firstItem = reset($firstItem);
            } else {
                $timestamp = key($firstItem);
                print("Duplicate history items for $fieldName at $timestamp:");
                print_r($firstItem);
            }
        }

        if (false && $issueKey == 'PDFUA-3' && str_contains('WCAG', $fieldName)) {
            print_r([
                '$itemsTimestamp' => $itemsTimestamp,
                '$realFieldName' => $realFieldName,
                '$fieldName' => $fieldName,
                '$firstItem' => $firstItem,
                '$fieldItems' => $fieldItems,
            ]);
        }

        if ($fieldName == 'Link') {
            $itemsUnixTime  = (new DateTimeImmutable($itemsTimestamp))->getTimestamp();
            // manual inspection confirms this was not initially set for any
            // extent issues, but we still want to find "cloned" issues.
            foreach ($firstItem as $linkItem) {
                $value = $linkItem['toString'];
                if (isset($value) && str_starts_with($value, 'This issue clones')) {
                    if ($itemsUnixTime - $issueCreatedUnixTime < 5) {
                        $originalIssue['ClonedFromKey'] = substr($value, 18);
                    }
                }
            }

        } elseif ($fieldName == 'Assignee') {
            $from = $firstItem['from'];
            if ($from != null) {
                $body = "$issueCreatorMention originally assigned issue to " . mentionAssignee($usersMap, $issueCreatorMention, $from);
                $import['comments'][] = [
                    'created_at' => $issueCreatedTimestamp,
                    'body' => exportAndMarkdown($j2mDir, "$issueKey-final-unassign.txt", $body),
                ];
            }

        } elseif ($fieldName == 'Status') {
            $value = $firstItem['fromString'];
            if (isset($statusMap[$value])) {
                $value = $statusMap[$value];
            }
            $originalIssue[$realFieldName] = $value;

        } elseif (str_starts_with($fieldName, 'Attachment')) {
            foreach ($fieldItems as $attachmentItemTimestamp => $attachmentItem) {
                if (isset($attachmentItem['from'])) {
                    $id = $attachmentItem['from'];
                    $attachmentHistory[$issueKey][$id][$attachmentItemTimestamp][] = $attachmentItem;
                } elseif (isset($firstItem['to'])) {
                    $id = $firstItem['to'];
                    $attachmentHistory[$issueKey][$id][$attachmentItemTimestamp][] = $attachmentItem;
                }
            }
            if (isset($firstItem['from'])) {
                $id = $firstItem['from'];
                $filename = $firstItem['fromString'];
                $originalIssue['AttachmentsFromHistory'][$id] = $firstItem;
                $attachmentsState[$issueKey][$filename][$id] = true;
            }

        } elseif (!isset($firstItem['from']) && !isset($firstItem['fromString'])) {
            // initial value of field was apparently unset, unset regardless of field
           $originalIssue[$realFieldName] = null;

        } elseif ($fieldName == 'Comment') {
            // ignore: goes in issue
        } elseif ($fieldName == 'Components') {
            // ignore: handled above
        } elseif ($fieldName == 'Reporter') {
            // ignore: manual inspection confirms this was not used for anything meaningful beyond creator
        } elseif ($fieldName == 'Workflow') {
            // ignore: jira internal

        } elseif ($fieldName == 'AT support') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'Concerning') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'Description') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'Example type') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'Flagged') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'Keywords') {
            $originalIssue[$realFieldName] = preg_split('/ +/', $firstItem['fromString']);
        } elseif ($fieldName == 'Marked-content sequences') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'Matterhorn Protocol') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'PDF 2.0') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'PDF/UA Parts') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'Pass / Fail') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'Reason') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'Structure Types') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'Tests') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'Title') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'UA Technique Tag') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'Use cases') {
            $originalIssue[$realFieldName] = preg_split('/ +/', $firstItem['fromString']);
        } elseif ($fieldName == 'WCAG 2.1 SC') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'WCAG 2.1 Success Criteria') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'WCAG 2.2 Success Criteria') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];
        } elseif ($fieldName == 'WCAG 2.2 PDF Technique') {
            $originalIssue[$realFieldName] = $firstItem['fromString'];


        } elseif (preg_match('/hecke/', $fieldName)) {
            $originalIssue[$realFieldName] = $firstItem['from'];

        } elseif (!in_array($fieldName, $unhandledFields)) {
            print("# field missed while processing history\n");
            print("} elseif (\$fieldName == '$fieldName') {\n");
            array_push($unhandledFields, $fieldName);
        }

    }

    foreach ($issue['fields'] as $fieldName => $fieldItem) {
        if (isset($fieldNamesMap[$fieldName])) {
            $fieldName = $fieldNamesMap[$fieldName];
        }
        $realFieldName = getFieldNameAtTime($fieldRenamesReverse, $fieldName, $issueCreatedUnixTime);
        if (isset($fieldItem) && !array_key_exists($realFieldName, $originalIssue)) {
            if (false) {
            } elseif ($fieldName == 'Assignee') {
                // ignore, handled specially elsewhere
            } elseif ($fieldName == 'Attachment') {
                // ignore, handled specially elsewhere
            } elseif ($fieldName == 'Comment') {
                // ignore, handled specially elsewhere
            } elseif ($fieldName == 'Components') {
                // ignore, handled specially elsewhere
            } elseif ($fieldName == 'Created') {
                // ignore, handled specially elsewhere
            } elseif ($fieldName == 'Creator') {
                // ignore, handled specially elsewhere
            } elseif ($fieldName == 'Development') {
                // ignore, jira-only thing
            } elseif ($fieldName == 'Issue Type') {
                // ignore, always Example and no equivalent in output
            } elseif ($fieldName == 'Last Viewed') {
                // ignore, jira-only thing
            } elseif ($fieldName == 'Linked Issues') {
                // ignore, handled specially elsewhere
            } elseif ($fieldName == 'Progress') {
                // ignore, jira-only thing
            } elseif ($fieldName == 'Project') {
                // ignore, jira-only thing
            } elseif ($fieldName == 'Rank') {
                // ignore, jira-only thing
            } elseif ($fieldName == 'Reporter') {
                // ignore, handled specially elsewhere
            } elseif ($fieldName == 'Sub-Tasks') {
                // ignore, jira-only thing
            } elseif ($fieldName == 'Updated') {
                // ignore, jira-only thing
            } elseif ($fieldName == 'Votes') {
                // ignore, jira-only thing
            } elseif ($fieldName == 'Watchers') {
                // ignore, jira-only thing
            } elseif ($fieldName == 'Work Ratio') {
                // ignore, jira-only thing
            } elseif ($fieldName == 'Σ Progress') {
                // ignore, jira-only thing

            } elseif ($fieldName == 'AT support') {
                $originalIssue[$realFieldName] = $fieldItem;
            } elseif ($fieldName == 'Example type') {
                $originalIssue[$realFieldName] = $fieldItem['value'];
            } elseif ($fieldName == 'Matterhorn Protocol') {
                $values = [];
                foreach ($fieldItem as $valueItem) {
                    $values[] = $valueItem['value'];
                }
                $originalIssue[$realFieldName] = $values;
            } elseif ($fieldName == 'Pass / Fail') {
                $originalIssue[$realFieldName] = $fieldItem['value'];
            } elseif ($fieldName == 'PDF/UA Parts') {
                $values = [];
                foreach ($fieldItem as $valueItem) {
                    $values[] = $valueItem['value'];
                }
                $originalIssue[$realFieldName] = $values;
            } elseif ($fieldName == 'Reason') {
                $originalIssue[$realFieldName] = $fieldItem['value'];
            } elseif ($fieldName == 'Structure Types') {
                $values = [];
                foreach ($fieldItem as $valueItem) {
                    $values[] = $valueItem['value'];
                }
                $originalIssue[$realFieldName] = $values;
            } elseif ($fieldName == 'UA Technique Tag') {
                $originalIssue[$realFieldName] = $fieldItem;
            } elseif ($fieldName == 'Use cases') {
                $originalIssue[$realFieldName] = $fieldItem;
            } elseif ($fieldName == 'WCAG 2.2 PDF Technique') {
                $originalIssue[$realFieldName] = $fieldItem['value'];
            } elseif ($fieldName == 'WCAG 2.2 Success Criteria') {
                $values = [];
                foreach ($fieldItem as $valueItem) {
                    $values[] = $valueItem['value'];
                }
                $originalIssue[$realFieldName] = $values;

            } elseif (preg_match('/hecke/', $fieldName)) {
                $originalIssue[$realFieldName] = preg_replace('/\.000/', '', $fieldItem);

            } else {
                print("} elseif (\$fieldName == '$fieldName') {\n");
                printf("$issueKey\t$fieldName\n");
                print_r($fieldItem);
                printf("\n\n");
            }
        }
    }

    if (!isset($history[$issueCreatedTimestamp])) {
        $history[$issueCreatedTimestamp] = [];
    }
    if (!isset($history[$issueCreatedTimestamp][$issueCreatorName])) {
        $history[$issueCreatedTimestamp][$issueCreatorName] = [];
    }
    if (!isset($history[$issueCreatedTimestamp][$issueCreatorName][$issueKey])) {
        $history[$issueCreatedTimestamp][$issueCreatorName][$issueKey] = [];
    }
    array_push($history[$issueCreatedTimestamp][$issueCreatorName][$issueKey], $originalIssue);

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
    //printf("Processed issue: %s (Idx: %d)\n", $issueKey, $count);
    $count++;

}

$fieldsConfig = yaml_parse(file_get_contents("$gitRepoDir/config/fields.yaml"));

$fieldsOrder = [];
$fieldsOrderConfig = $fieldsConfig['order'];
foreach (array_merge($fieldsOrderConfig['prototype'], $fieldsOrderConfig['other']) as $orderedField) {
    if (isset($fieldAllNames[$orderedField])) {
        $fieldsOrder[$orderedField] = $fieldAllNames[$orderedField];
    } else {
        $fieldsOrder[$orderedField] = [$orderedField];
    }
}

$fieldGroups = [];
$fieldTypes = [];
foreach ($fieldsConfig['fields'] as $groupName => $fieldConfigsGroup) {
    foreach ($fieldConfigsGroup as $fieldName => $fieldConfig) {
        $fieldGroups[$fieldName] = $groupName;
        $fieldTypes[$fieldName] = $fieldConfig['type'];
    }
}

$fieldsOrder['Concerning'] = ['Concerning'];
$fieldGroups['Concerning'] = 'uncommon';
$fieldTypes['Concerning'] = 'List zero or many';

$fieldsOrder['PDF 2.0'] = ['PDF 2.0'];
$fieldGroups['PDF 2.0'] = 'uncommon';
$fieldTypes['PDF 2.0'] = 'List zero or many';

$fieldTypes['Description'] = 'text';
$fieldTypes['Tests'] = 'text';

$fieldComments = [
    "Issue Number"              => "The GitHub issue number",
    "UA Technique Tag"          => "LEAVE BLANK FOR NEW ISSUES",
    "PDF/UA Parts"              => "List of PDF/UA-1, PDF/UA-2, or both",
    "Use cases"                 => "List of at least one",
    "Matterhorn Protocol"       => "List of zero or many",
    "WCAG 2.2 PDF Technique"    => "PDF1 - PDF23",
    "WCAG 2.2 Success Criteria" => "List of zero or many",
    "Marked-content sequences"  => "List of zero or many",
    "Structure Types"           => "List of zero or many",
];

$fieldsConfig = [
    'groups'     => $fieldGroups,
    'types'      => $fieldTypes,
    'order'      => $fieldsOrder,
    'comments'   => $fieldComments,
];

//print_r($fieldsConfig);

ksort($history);

//print_r($history);
//exit(0);
file_put_contents("$projectDataTag/fields-config.yaml", yaml_emit($fieldsConfig, YAML_UTF8_ENCODING, YAML_LN_BREAK));
file_put_contents("$projectDataTag/history.yaml", yaml_emit($history, YAML_UTF8_ENCODING, YAML_LN_BREAK));

$unhandledFields = [];
$commitMessages = [];
$attachmentFilenames = [];

$gitCommitCount = 0;

//$history = [];
foreach ($history as $timestamp => $simultaneousItems) {
    //printf("$timestamp\n");

    putenv("GIT_AUTHOR_DATE=$timestamp");
    putenv("GIT_COMMITTER_DATE=$timestamp");

    //ksort($simultaneousItems);

    foreach ($simultaneousItems as $authorName => $authorItems) {
        //printf("\t$authorName\n");

        if (isset($usersMap[$authorName])) {
            $userMap = $usersMap[$authorName];
            $gitName = $userMap['name'];
            $email = $userMap['email'];

            putenv("GIT_AUTHOR_NAME=$gitName");
            putenv("GIT_COMMITTER_NAME=$gitName");

            putenv("GIT_AUTHOR_EMAIL=$email");
            putenv("GIT_COMMITTER_EMAIL=$email");
        } else {
            printf("Unknown user for git: $authorName\n");

            putenv("GIT_AUTHOR_NAME=$authorName");
            putenv("GIT_COMMITTER_NAME=$authorName");

            putenv("GIT_AUTHOR_EMAIL=<>");
            putenv("GIT_COMMITTER_EMAIL=<>");
        }

        $pendingIssueInfos = [];
        $pendingFields = [];
        $valuesAreConsistent = true;
        $createdIssues = 0;
        $commitItems = 0;
        $ignoreItems = 0;
        $oldInfo = [];

        foreach ($authorItems as $issueKey => $authorItemIssues) {
            //printf("\t\t$issueKey\n");
            $issueNum = substr($issueKey, 6);
            $issueDir = sprintf(
                "%s/wip/ua-%02dxx/ua-%03dx/ua-%04d",
                $gitRepoDir,
                $issueNum / 100,
                $issueNum / 10,
                $issueNum
            );

            $issueInfo = [];

            $removedAttachments = [];

            if ($issueKey == 'PDFUA-77' || $issueKey == 'PDFUA-79') {
                printf("Items for $issueKey $authorName $timestamp:\n");
                print_r($authorItemIssues);
            }

            foreach ($authorItemIssues as $historyItem) {
                //print_r($historyItem);
                $fieldName = $historyItem['field'];

                if (isset($fieldNamesMap[$fieldName])) {
                    $fieldName = $fieldNamesMap[$fieldName];
                }
                $realFieldName = $fieldName;

                if (isset($fieldFinalNames[$fieldName])) {
                    $fieldName = $fieldFinalNames[$fieldName];
                }

                {

                if ($fieldName == 'IssueCreated') {
                    if (count($issueInfo) > 0) {
                        print("Unexpected issueInfo already set when creating issue:\n");
                        print_r($issueInfo);
                    }
                    if (count($issueInfo) > 0) {
                        print("Unexpected issueInfo already set when creating issue:\n");
                        print_r($issueInfo);
                    }
                    $info = $historyItem;
                    if (false && $issueNum == 144) {
                        print_r($info);
                    }
                    $message = "Created initial data for issue #$issueNum";
                    if (isset($historyItem['ClonedFromKey'])) {
                        $clonedIssueNum = substr($historyItem['ClonedFromKey'], 6);
                        $clonedIssueDir = sprintf(
                            "%s/wip/ua-%02dxx/ua-%03dx/ua-%04d",
                            $gitRepoDir,
                            $clonedIssueNum / 100,
                            $clonedIssueNum / 10,
                            $clonedIssueNum
                        );
                        $info = readInfo($clonedIssueDir);
                        $info = array_merge($info, $historyItem);
                        unset($info['Related issues']);
                        unset($info['Duplicates issues']);
                        unset($info['Blocking issues']);
                        $message = "Cloned data from issue #$clonedIssueNum to issue #$issueNum";
                    } else {
                    }
                    writeInfo($fieldsConfig, $issueDir, $info, false);
                    if (isset($info['Description'])) {
                        writeMarkdown($j2mDir, $issueDir, 'description', $info['Description'], $timestamp);
                    }
                    if (isset($info['Tests'])) {
                        writeMarkdown($j2mDir, $issueDir, 'tests', $info['Tests'], $timestamp);
                    }
                    foreach ($historyItem['AttachmentsFromHistory'] as $id => $attachmentItem) {
                        // create attachments that will later be deleted
                        $filename = $attachmentItem['fromString'];
                        $file = "$issueDir/$filename";
                        $attachmentFilenames[$id] = $filename;
                        $attachmentsState[$issueKey][$filename][$id] = true;
                        if (isset($attachments[$issueKey][$id])) {
                            $sourceFile = "$attachmentsDir/$issueKey/$filename";
                            // TODO handle multiple files with same filename added at issue creation time
                            // did not occur for LWG issues
                            copy($sourceFile, $file);
                        } else {
                            file_put_contents($file, "");
                        }
                    }
                    foreach ($attachments[$issueKey] as $id => $attachment) {
                        if (count($attachmentHistory[$issueKey][$id]) == 0) {
                            // attachment has no add/remove history, add as part of issue creation
                            $filename = $attachment['filename'];
                            $attachmentFilenames[$id] = $filename;
                            $sourceFile = "$attachmentsDir/$issueKey/$filename";
                            $file = "$issueDir/$filename";
                            copy($sourceFile, $file);
                            $attachmentsState[$issueKey][$filename][$id] = true;
                        }
                    }
                    gitCommit($gitRepoDir, $gitCommitCount, $message);
                    $createdIssues++;

                } elseif (str_starts_with($fieldName, 'RenameValuesOn:')) {
                    if (is_readable("$issueDir/technique-info.yaml")) {
                        $fieldName = preg_replace('/^RenameValuesOn:/', '', $fieldName);
                        $info = readInfo($issueDir);
                        $renameFrom = $historyItem['from'];
                        if ($info[$fieldName] == $renameFrom) {
                            $renameTo = $historyItem['to'];
                            $info[$fieldName] = $renameTo;
                            writeInfo($fieldsConfig, $issueDir, $info, false);
                            $commitMessages[$issueKey][] = sprintf("Rename %s value for $fieldName to %s", maybeQuote($renameFrom), maybeQuote($renameTo));
                            $commitItems++;
                        }
                    }

                } elseif ($fieldName == 'RenameField') {
                    if (is_readable("$issueDir/technique-info.yaml")) {
                        $info = readInfo($issueDir);
                        $renameFrom = $historyItem['from'];
                        if (isset($info[$renameFrom])) {
                            $renameTo = $historyItem['to'];
                            $info[$renameTo] = $info[$renameFrom];
                            unset($info[$renameFrom]);
                            writeInfo($fieldsConfig, $issueDir, $info, false);
                            $commitMessages[$issueKey][] = sprintf("Rename %s to %s", maybeQuote($renameFrom), maybeQuote($renameTo));
                            $commitItems++;
                        }
                    }

                } elseif ($fieldName == 'Link') {
                    $value = $historyItem['toString'];
                    $action = 'add';
                    $issueFieldName = null;
                    $issueReference = "error";
                    if (!isset($value)) {
                        $action = 'delete';
                        $value = $historyItem['fromString'];
                    }
                    printf("Link action = $action: '$value' $issueKey $authorName $timestamp\n");
                    if (isset($value)) {
                        // This issue blocks
                        // This issue clones
                        // This issue duplicates
                        // This issue is blocked by
                        // This issue is cloned by
                        // This issue is duplicated by
                        // This issue relates to
                        if (false) {
                        } elseif (str_starts_with($value, 'This issue relates to')) {
                            $issueFieldName = 'Related issues';
                        } elseif (str_starts_with($value, 'This issue duplicates')) {
                            $issueFieldName = 'Duplicates issues';
                        } elseif (str_starts_with($value, 'This issue is blocked by')) {
                            $issueFieldName = 'Blocking issues';
                        }
                        $issueReference = preg_replace('/.* PDFUA-(\d+).*/', '#\1', $value);
                    } else {
                        print_r($historyItem);
                    }
                    if ($issueFieldName != null) {
                        if (!key_exists($issueFieldName, $issueInfo)) {
                            $issueInfo[$issueFieldName] = [
                                'add' => [],
                                'delete' => [],
                            ];
                        } else {
                            print_r([
                                'key_exists($issueFieldName, $issueInfo)' => key_exists($issueFieldName, $issueInfo),
                                $issueInfo[$issueFieldName],
                                $historyItem,
                            ]);
                        }
                        $commitMessage = $value;
                        $commitMessage = preg_replace('/This issue/', "Issue #$issueNum", $commitMessage);
                        $commitMessage = preg_replace('/PDFUA-/', "issue #", $commitMessage);
                        if ($action == 'add') {
                            $issueInfo[$issueFieldName]['add'][] = $issueReference;
                        } else {
                            $issueInfo[$issueFieldName]['delete'][] = $issueReference;
                            $commitMessage = preg_replace('/ is blocked /', ' is not blocked ', $commitMessage);
                            $commitMessage = preg_replace('/ relates /', ' does not relate ', $commitMessage);
                            $commitMessage = preg_replace('/ duplicates /', ' does not duplicate ', $commitMessage);
                        }
                        $commitMessages[$issueKey][] = $commitMessage;
                        $commitItems++;
                    }

                } elseif ($fieldName == 'Attachment') {

                    if (isset($historyItem['to'])) {
                        $id = $historyItem['to'];
                        $inFilename = $historyItem['toString'];
                        $outFilename = $inFilename;

                        $outFile = "$issueDir/$outFilename";
                        while (is_file($outFile)) {
                            $pathinfo = pathinfo($outFilename);
                            $basename = $pathinfo['filename'];
                            $extension = $pathinfo['extension'];
                            $extension = $extension ? $extension : "";

                            $num = 1;
                            if (preg_match('/\.(\d+)$/', $basename)) {
                                $num = 1 + preg_replace('/.*\.(\d+)$/', '\1', $basename);
                                $basename = preg_replace('/(.*)\.\d+$/', '\1', $basename);
                            }

                            $outFilename = "$basename.$num.$extension";
                            $outFile = "$issueDir/$outFilename";
                        }
                        $attachmentFilenames[$id] = $outFilename;
                        $attachmentsState[$issueKey][$inFilename][$id] = true;

                        if (isset($attachments[$issueKey][$id])) {
                            $sourceFile = "$attachmentsDir/$issueKey/$inFilename";
                            if (file_exists("$attachmentsDir/$issueKey/$outFilename")) {
                                $sourceFile = "$attachmentsDir/$issueKey/$outFilename";
                            }
                            copy($sourceFile, $outFile);
                            $addMessage = sprintf("Add %s (Jira id $id) to issue #$issueNum", maybeQuote($outFilename));
                        } else {
                            file_put_contents($outFile, "");
                            $addMessage = sprintf("Add %s (empty placeholder; Jira id $id) to issue #$issueNum", maybeQuote($outFilename));
                        }

                        $removeMessage = sprintf("Remove %s (Jira id $id) from issue #$issueNum", maybeQuote($outFilename));
                        if (isset($removedAttachments[$inFilename])) {
                            $removeItem = $removedAttachments[$inFilename];
                            // TODO handle deletion of attachment files with duplicate names
                            unset($removedAttachments[$inFilename]);
                            if ($removeItem['from'] != $id) {
                                foreach (array_keys($commitMessages[$issueKey], $removeMessage, true) as $key) {
                                    unset($commitMessages[$issueKey][$key]);
                                    $commitMessages[$issueKey][$key] = sprintf("Replace %s (Jira id $id) in issue #$issueNum", maybeQuote($outFilename));
                                    $addMessage = null;
                                }
                            } else {
                                print("\nAttachment remove/add don't match: $issueKey $authorName $timestamp\n");
                                print_r($removeItem);
                                print_r($historyItem);
                            }
                        }

                        if ($addMessage) {
                            $commitMessages[$issueKey][] = $addMessage;
                        }
                    } elseif (isset($historyItem['from'])) {
                        $id = $historyItem['from'];
                        $origFilename = $historyItem['fromString'];
                        if (isset($attachmentFilenames[$id])) {
                            $filename = $attachmentFilenames[$id];
                        } else {
                            $filename = $origFilename;
                        }
                        $removedAttachments[$origFilename] = $historyItem;
                        $file = "$issueDir/$filename";
                        $commitMessages[$issueKey][] = sprintf("Remove %s (Jira id $id) from issue #$issueNum", maybeQuote($filename));
                        unset($attachmentsState[$issueKey][$origFilename][$id]);
                        unlink($file);
                        foreach ($attachmentsState[$issueKey][$origFilename] as $id => $exists) {
                            $message = normalizeMessages($commitMessages, "Multiple updates:\n\n");
                            gitCommit($gitRepoDir, $gitCommitCount, $message, true);
                            $commitMessages = [];

                            if (!isset($attachmentFilenames[$id])) {
                                print_r([
                                    '$id' => $id,
                                    '$historyItem' => $historyItem,
                                    '$attachmentFilenames' => $attachmentFilenames,
                                    '$attachmentsState' => $attachmentsState,
                                ]);
                            }
                            $actualFilename = $attachmentFilenames[$id];
                            $pathinfo = pathinfo($actualFilename);
                            $basename = $pathinfo['filename'];
                            $extension = $pathinfo['extension'];
                            $extension = $extension ? $extension : "";

                            $num = 1;
                            if (preg_match('/\.(\d+)$/', $basename)) {
                                $num = preg_replace('/.*\.(\d+)$/', '\1', $basename);
                                $basename = preg_replace('/(.*)\.\d+$/', '\1', $basename);
                            }

                            if ($num == 1) {
                                $newFilename = "$basename.$extension";
                            } else {
                                $num--;
                                $newFilename = "$basename.$num.$extension";
                            }
                            while ($num > 1 && !is_file("$issueDir/$newFilename")) {
                                $num--;
                                if ($num == 1) {
                                    $newFilename = "$basename.$extension";
                                } else {
                                    $num--;
                                    $newFilename = "$basename.$num.$extension";
                                }
                            }
                            if (is_file("$issueDir/$newFilename")) {
                                $num++;
                            }
                            if ($num == 1) {
                                $newFilename = "$basename.$extension";
                            } else {
                                $num--;
                                $newFilename = "$basename.$num.$extension";
                            }

                            if ($actualFilename != $newFilename) {
                                if (!rename("$issueDir/$actualFilename", "$issueDir/$newFilename")) {
                                    printf("Rename failed for $issueKey $authorName $timestamp:\n  from: %s\n    to: %s\n", "$issueDir/$actualFilename", "$issueDir/$newFilename");
                                    print_r([
                                        '$id' => $id,
                                        '$attachmentFilenames[$id]' => $attachmentFilenames[$id],
                                        '$historyItem' => $historyItem,
                                    ]);
                                }
                                $attachmentFilenames[$id] = $newFilename;
                                $commitMessages[$issueKey][] = sprintf("Rename %s to %s (Jira id $id) for issue #$issueNum", maybeQuote($actualFilename), maybeQuote($newFilename));
                            }
                        }

                    } else {
                        print("\nWeird attachment for $issueKey $authorName $timestamp\n");
                        print_r($historyItem);
                    }

                } elseif ($fieldName == 'Components') {
                    // This looks like the only multi-value field where
                    // fromString and toString contain only one value. All
                    // other multi-value fields list all selected values
                    // separated by commas in from/toString.
                    //print("\n$issueKey $timestamp\n");
                    //print_r($historyItem);
                    if (!key_exists($realFieldName, $issueInfo)) {
                        $issueInfo[$realFieldName] = [
                            'add' => [],
                            'delete' => [],
                        ];
                    }
                    $newValue = $historyItem['toString'];
                    if ($newValue != null) {
                        $issueInfo[$realFieldName]['add'][] = $newValue;
                    } else {
                        $issueInfo[$realFieldName]['delete'][] = $historyItem['fromString'];
                    }
                    $commitItems++;

                } elseif ($fieldName == 'Status') {
                    $value = $historyItem['toString'];
                    if (isset($statusMap[$value])) {
                        $value = $statusMap[$value];
                    }
                    $issueInfo[$realFieldName] = $value;
                    $commitItems++;

                } elseif ($fieldName == 'Assignee') {
                    // ignore: goes in issue
                    $ignoreItems++;
                } elseif ($fieldName == 'Comment') {
                    // ignore: goes in issue
                    $ignoreItems++;
                } elseif ($fieldName == 'Reporter') {
                    // ignore: manual inspection confirms this was not used for anything meaningful beyond creator
                    $ignoreItems++;
                } elseif ($fieldName == 'Workflow') {
                    // ignore: jira internal
                    $ignoreItems++;

                } elseif ($realFieldName == 'WCAG 2.1 SC') {
                    $issueInfo[$realFieldName] = preg_replace('/ /', ', ', $historyItem['toString']);
                    if (false) {
                        print_r([
                            $historyItem['toString'],
                            $issueInfo[$realFieldName]
                        ]);
                    }
                    $commitItems++;

                } elseif ($fieldName == 'Description') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    writeMarkdown($j2mDir, $issueDir, 'description', $historyItem['toString'], $timestamp);
                    $commitItems++;
                } elseif ($fieldName == 'Tests') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    writeMarkdown($j2mDir, $issueDir, 'tests', $historyItem['toString'], $timestamp);
                    $commitItems++;

                } elseif ($fieldName == 'AT support') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'Concerning') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'Example type') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'Flagged') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'Keywords') {
                    $issueInfo[$realFieldName] = preg_split('/ +/', $historyItem['toString']);
                    $commitItems++;
                } elseif ($fieldName == 'Marked-content sequences') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'Matterhorn Protocol') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'PDF 2.0') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'PDF/UA Parts') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'Pass / Fail') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'Reason') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'Structure Types') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'Title') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'UA Technique Tag') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'Use cases') {
                    $issueInfo[$realFieldName] = preg_split('/ +/', $historyItem['toString']);
                    $commitItems++;
                } elseif ($fieldName == 'WCAG 2.1 Success Criteria') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'WCAG 2.2 Success Criteria') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;
                } elseif ($fieldName == 'WCAG 2.2 PDF Technique') {
                    $issueInfo[$realFieldName] = $historyItem['toString'];
                    $commitItems++;


                } elseif (preg_match('/hecke/', $fieldName)) {
                    $issueInfo[$realFieldName] = $historyItem['to'];
                    $commitItems++;

                } elseif (!in_array($fieldName, $unhandledFields)) {
                    print("\n# field missed while writing history\n");
                    print("} elseif (\$fieldName == '$fieldName') {\n\n");
                    array_push($unhandledFields, $fieldName);
                }

                }

            }

            if (count($issueInfo) > 0) {
                $oldInfo = readInfo($issueDir);
                $info = $oldInfo;

                foreach (array(
                    'Component',
                    'Components',
                    'Related issues',
                    'Duplicates issues',
                    'Blocking issues',
                ) as $listFieldKey) {
                    if (key_exists($listFieldKey, $issueInfo)) {
                        $listFields = [];
                        if (key_exists($listFieldKey, $info)) {
                            if (is_array($info[$listFieldKey])) {
                                $listFields = $info[$listFieldKey];
                            } else {
                                $listFields = preg_split('/,\s*/', $info[$listFieldKey]);
                            }
                        }
                        foreach ($issueInfo[$listFieldKey]['add'] as $listField) {
                            $listFields[] = $listField;
                        }
                        foreach ($issueInfo[$listFieldKey]['delete'] as $listField) {
                            foreach (array_keys($listFields, $listField, true) as $key) {
                                unset($listFields[$key]);
                                if (str_ends_with($listFieldKey, 'issues')) {
                                    break;
                                }
                            }
                        }
                        /*
                        if (str_ends_with($listFieldKey, 'issues')) {
                            $listFields = preg_replace('/#/', '', $listFields);
                            $listFields = array_unique($listFields, SORT_NUMERIC);
                            $listFields = preg_replace('/^/', '#', $listFields);
                        } else {
                        */
                        if (!str_ends_with($listFieldKey, 'issues')) {
                            $listFields = array_unique($listFields);
                        }
                        $issueInfo[$listFieldKey] = join(", ", $listFields);
                        $info[$listFieldKey] = $issueInfo[$listFieldKey];
                    }
                }

                if (count($issueInfo) > 0) {
                    $pendingIssueInfos[$issueKey] = $issueInfo;
                }

                $beforeInfo = $info;
//                print_r(['before: ' => $info]);
                $info = array_merge($info, $issueInfo);
//                print_r(['after: ' => $info]);
                writeInfo($fieldsConfig, $issueDir, $info, false);

                $tmpPendingFields = [];
                foreach ($issueInfo as $pendingFieldName => $pendingFieldValue) {
                    if (!str_ends_with($pendingFieldName, 'issues')) {
                        if (!isset($tmpPendingFields[$pendingFieldName])) {
                            $tmpPendingFields[$pendingFieldName] = [];
                        }
                        array_push($tmpPendingFields[$pendingFieldName], $pendingFieldValue);
                        $tmpPendingFields[$pendingFieldName] = array_unique($tmpPendingFields[$pendingFieldName]);
                        sort($tmpPendingFields[$pendingFieldName]);
                    }
                }

                if (count($pendingFields) > 0) {
                    if ($tmpPendingFields != $pendingFields) {
                        $valuesAreConsistent = false;
                        printf("Inconsistent values:\n");
                        print_r([
                            '$pendingFields' => $pendingFields,
                            '$tmpPendingFields' => $tmpPendingFields,
                            '$authorItems' => $authorItems,
                        ]);
                    }
                } else {
                    $pendingFields = $tmpPendingFields;
                }
            }
        }

        if (false && $createdIssues == 0) {
            printf('$authorItems: ');
            print_r($authorItems);
            printf('$pendingIssueInfos: ');
            print_r($pendingIssueInfos);
            printf('$pendingFields: ');
            print_r($pendingFields);
        }

        $issueString = 'multiple issues';
        $pendingIssuesCount = count($pendingIssueInfos);
        if ($pendingIssuesCount > 0) {
            if ($pendingIssuesCount == 1) {
                $issueString = "issue #$issueNum";
            } else {
                $issueKeys = array_keys($pendingIssueInfos);
                $issueNums = preg_replace('/PDFUA-/', '#', $issueKeys);
                $issueString = toListString($issueNums, 40, 'issue', 'issues');

                if (false) {
                    printf("Simultaneous items for $authorName $timestamp:\n");
                    print_r([
                        '$issueString' => $issueString,
                        '$valuesAreConsistent' => $valuesAreConsistent,
                        '$authorItems' => $authorItems,
                        '$pendingFields' => $pendingFields,
                    ]);
                }
            }

            $message = "Update multiple fields in $issueString";
            $pendingFieldsCount = count($pendingFields);
            if ($pendingFieldsCount == 1) {
                $name = key($pendingFields);
                $messageName = maybeQuote($name);

                $value = $pendingFields[$name];
                if (count($value) == 1) {
                    $value = $value[0];
                }

                $finalName = $name;
                if (isset($fieldNamesMap[$name])) {
                    $finalName = $fieldNamesMap[$name];
                }
                if (isset($fieldFinalNames[$finalName])) {
                    $finalName = $fieldFinalNames[$finalName];
                }

                if ($valuesAreConsistent && str_starts_with($fieldTypes[$finalName], 'List')) {
                    $message = sprintf("Update list $name to %s in $issueString", is_array($value) ? join(', ', $value) : $value);

                    $oldValue = [];
                    if (isset($oldInfo[$name])) {
                        $oldValue = $oldInfo[$name];
                    }
                    $newValue = $value;
                    if (!is_array($oldValue)) {
                        $oldValue = preg_split('/,\s*/', $oldValue);
                    }
                    if (!is_array($newValue)) {
                        $newValue = preg_split('/,\s*/', $newValue);
                    }

                    if (str_ends_with($finalName, 'issues')) {
                        $oldValue = preg_replace('/#/', '', $oldValue);
                        $newValue = preg_replace('/#/', '', $newValue);
                        sort($oldValue, SORT_NUMERIC);
                        sort($newValue, SORT_NUMERIC);
                        $oldValue = preg_replace('/^/', '#', $oldValue);
                        $newValue = preg_replace('/^/', '#', $newValue);
                    } else {
                        sort($oldValue);
                        sort($newValue);
                    }

                    $oldValue = array_unique($oldValue);
                    $newValue = array_unique($newValue);

                    $onlyOld = array_diff($oldValue, $newValue);
                    $onlyNew = array_diff($newValue, $oldValue);

                    if (str_ends_with($finalName, 'issues')) {
                        $oldMessage = toListString($onlyOld, 40, 'issue', 'issues');
                        $newMessage = toListString($onlyNew, 40, 'issue', 'issues');
                    } else {
                        $oldMessage = toListString($onlyOld, 40);
                        $newMessage = toListString($onlyNew, 40);
                    }

                    if (strlen($oldMessage) > 0) {
                        if (strlen($newMessage) > 0) {
                            $message = "Update $messageName in $issueString: Remove $oldMessage; add $newMessage";
                        } else {
                            $message = "Remove $oldMessage from $messageName in $issueString";
                        }
                    } elseif (strlen($newMessage) > 0) {
                        $message = "Add $newMessage to $messageName in $issueString";
                    }

                    if (false) {
                        print_r([
                            'all_old' => $oldValue,
                            'all_new' => $newValue,
                            'onlyOld' => $onlyOld,
                            'onlyNew' => $onlyNew,
                            'old_Msg' => $oldMessage,
                            'new_Msg' => $newMessage,
                            'message' => $message,
                        ]);
                    }

                } elseif ($valuesAreConsistent && is_string($value) && !str_contains($value, "\n") && strlen($value) < 40) {
                    if ($value == '') {
                        if ($name == 'Description' || $name == 'Tests') {
                            $message = "Clear $messageName in $issueString";
                        } else {
                            $message = "Unset $messageName in $issueString";
                        }
                    } else {
                        $valueMessage = maybeQuote($value);
                        $message = "Update $messageName to $valueMessage in $issueString";
                    }
                } else {
                    $message = "Update $messageName in $issueString";
                }
            } elseif ($pendingFieldsCount > 1) {
                $messageName = toListString(array_keys($pendingFields), 40, 'field', 'fields');
                $message = "Update $messageName in $issueString";
            } else {
                $message = "";
            }

            if (count($commitMessages) > 0) {
                $prefixMany = $message ? "" : "Multiple updates:\n\n";
                $message .= ($message ? ", and:\n\n" : "") . normalizeMessages($commitMessages, );
            }

            //print_r($pendingIssueInfos);
            if (str_contains($message, '  ')) {
                printf("\nSomething wrong with '$message' for $authorName at $timestamp:\n");
                print_r($authorItems);
                print_r($pendingIssueInfos);
                print_r($pendingFields);
            }
            try {
                gitCommit($gitRepoDir, $gitCommitCount, $message, preg_match('/Jira id |^Update (Descripition|Tests) /', $message));
            } catch (Exception $e) {
                printf("\ngit commit failed for $authorName at $timestamp ???\n");
                //printf("\tIssues: %s\n", join(', ', array_keys($pendingIssueInfos)));
                //printf("\tFields: %s\n", join(', ', array_keys($pendingFields)));

                print_r([
                    '$beforeInfo' => $beforeInfo,
                    '$info' => $info,
                    '$authorItems' => $authorItems,
                    '$pendingIssueInfos' => $pendingIssueInfos,
                    '$pendingFields' => $pendingFields,
                ]);
                //throw $e;
                if (preg_match('/Jira id |^Update (Descripition|Tests) /', $message)) {
                    gitCommit($gitRepoDir, $gitCommitCount, $message, true);
                }
            }
        } elseif (count($commitMessages) > 0) {
            try {
                $message = normalizeMessages($commitMessages, "Multiple updates:\n\n");
                gitCommit($gitRepoDir, $gitCommitCount, $message, preg_match('/Jira id |^Update (Descripition|Tests) /', $message));
            } catch (Exception $e) {
                printf("\ngit commit failed for $authorName at $timestamp ???\n");
                print_r([
                    '$info' => $info,
                    '$authorItems' => $authorItems,
                    '$pendingIssueInfos' => $pendingIssueInfos,
                    '$pendingFields' => $pendingFields,
                ]);
                //printf("\tIssues: %s\n", join(', ', array_keys($pendingIssueInfos)));
                //printf("\tFields: %s\n", join(', ', array_keys($pendingFields)));
                //print_r($authorItems);
                //throw $e;
                if (preg_match('/Jira id |^Update (Descripition|Tests) /', $message)) {
                    gitCommit($gitRepoDir, $gitCommitCount, $message, true);
                }
            }
        } elseif ($createdIssues == 0) {
            //  Workflow
            $noUpdatesOk = [
                'assignee',
                'Attachment',
                'Comment',
                'Link',
                'Workflow',
                'reporter',
            ];
            $itemFields = [];
            foreach ($authorItems as $issueKey => $authorItemIssues) {
                foreach ($authorItemIssues as $authorItem) {
                    $itemFields[] = $authorItem['field'];
                }
            }
            $itemFields = array_unique($itemFields);
            $badFields = array_diff($itemFields, $noUpdatesOk);
            if (count($badFields) > 0) {
/*
                printf("%d %d %d %s %s\n", count($authorItems), count(current($authorItems)), count(current(current($authorItems))), current(current($authorItems))['field'], in_array(current(current($authorItems))['field'], $noUpdatesOk));
                print_r($noUpdatesOk);
                print_r(current(current($authorItems))['field']);
*/
                printf("\nNo updates for $authorName at $timestamp ???\n");
                printf("\tIssues: %s\n", join(', ', array_keys($pendingIssueInfos)));
                printf("\tFields: %s\n", join(', ', array_keys($pendingFields)));
                print_r($badFields);
                //print_r($authorItems);
                printf("\n\n");
                exit(0);
            }
        }
        $commitMessages = [];

    }
}

if (true) {
    // remove parenthetical information from Structure Type values
    foreach ($issueIds as $issueId) {
        $issueKey = $project . '-' . $issueId;
        $issueNum = $issueId;
        $issueDir = sprintf(
            "%s/wip/ua-%02dxx/ua-%03dx/ua-%04d",
            $gitRepoDir,
            $issueNum / 100,
            $issueNum / 10,
            $issueNum
        );
        $info = readInfo($issueDir);
        if (array_key_exists('Structure Types', $info)) {
            $info['Structure Types'] = preg_replace('/ \(.*\)\s*/', '', $info['Structure Types']);
        }
        writeInfo($fieldsConfig, $issueDir, $info, false);
    }
    putenv("GIT_AUTHOR_DATE=$now");
    putenv("GIT_COMMITTER_DATE=$now");

    putenv("GIT_AUTHOR_NAME=William Kilian");
    putenv("GIT_COMMITTER_NAME=William Kilian");

    putenv("GIT_AUTHOR_EMAIL=william.kilian@targetstream.com");
    putenv("GIT_COMMITTER_EMAIL=william.kilian@targetstream.com");
    gitCommit($gitRepoDir, $gitCommitCount, 'Remove parentheticals from Structure Type values');
}

if (false) {
    // rewrite technique-info.yaml files with comments
    foreach ($issueIds as $issueId) {
        $issueKey = $project . '-' . $issueId;
        $issueNum = $issueId;
        $issueDir = sprintf(
            "%s/wip/ua-%02dxx/ua-%03dx/ua-%04d",
            $gitRepoDir,
            $issueNum / 100,
            $issueNum / 10,
            $issueNum
        );
        $info = readInfo($issueDir);
        writeInfo($fieldsConfig, $issueDir, $info, true);
    }
    putenv("GIT_AUTHOR_DATE=$now");
    putenv("GIT_COMMITTER_DATE=$now");

    putenv("GIT_AUTHOR_NAME=William Kilian");
    putenv("GIT_COMMITTER_NAME=William Kilian");

    putenv("GIT_AUTHOR_EMAIL=william.kilian@targetstream.com");
    putenv("GIT_COMMITTER_EMAIL=william.kilian@targetstream.com");
    gitCommit($gitRepoDir, $gitCommitCount, 'Add comments to technique-info.yaml metadata files');
}

function normalizeMessages($commitMessages, $prefixMany = "Multiple updates:\n\n") {
    $retVal = "";

    $messageTypes = [];
    $relatedIssues = [];
    $unrelatedIssues = [];

    foreach ($commitMessages as $issueKey => $messages) {
        foreach ($messages as $message) {
            $issue1 = preg_replace('/Issue #(\d+).*/', '\1', $message);
            $issue2 = preg_replace('/.* issue #(\d+).*/', '\1', $message);
            if (false) {
            } elseif (preg_match('/Jira id /', $message)) {
                $messageTypes['files updated'] = true;
            } elseif (preg_match('/ is blocked | is not blocked /', $message)) {
                $messageTypes['blockers updated'] = true;
            } elseif (preg_match('/ relates /', $message)) {
                $messageTypes['related issue updates'] = true;
                $relatedIssues[$issue1][$issue2] = true;
            } elseif (preg_match('/ does not relate /', $message)) {
                $messageTypes['unrelated issue updates'] = true;
                $unrelatedIssues[$issue1][$issue2] = true;
            } elseif (preg_match('/ duplicates | does not duplicate /', $message)) {
                $messageTypes['duplication updates'] = true;
            } else {
                $messageTypes['other'] = true;
            }
        }
    }

    if (count($commitMessages) == 1) {

        $issueKey = key($commitMessages);
        $commitMessages = current($commitMessages);
        $issueNum = preg_replace('/PDFUA-/', '', $issueKey);
        $commitMessages = array_unique($commitMessages);

        if (count($commitMessages) == 1) {
            $retVal = current($commitMessages);
        } else {
            if ($prefixMany == "Multiple updates:\n\n") {
                $prefixMany = "Multiple updates for issue #$issueNum:\n\n";
                if (count($messageTypes) == 1 && key($messageTypes) != 'other') {
                    $prefixMany = preg_replace('/updates/', key($messageTypes), $prefixMany);
                }
                $commitMessages = preg_replace("/ (for|to|from|in) issue #$issueNum\$/", '', $commitMessages);
            }
            $retVal = $prefixMany . "- " . join("\n- ", $commitMessages);
        }

    } else {

        printf("Commit messages:\n");
        print_r($commitMessages);

        $tmp = [];
        foreach($commitMessages as $issueMessages) {
            $tmp = array_merge($tmp, $issueMessages);
        }
        $commitMessages = array_unique($tmp);

        if (count($commitMessages) == 1) {
            $retVal = current($commitMessages);
        } else {
            if ($prefixMany == "Multiple updates:\n\n") {
                $prefixMany = "Multiple updates for multiple issues:\n\n";
                if (count($messageTypes) == 1 && key($messageTypes) != 'other') {
                    $prefixMany = preg_replace('/updates/', key($messageTypes), $prefixMany);
                    if (str_contains(key($messageTypes), 'relate')) {
                        $mutuals = [];
                        $nonMutuals = [];
                        $maybeNot = "";
                        $commitMessages = [];
                        if (key($messageTypes) == 'related issue updates') {
                            $prefixMany = "Related issues updated:\n\n";
                            foreach ($relatedIssues as $issue1 => $map) {
                                foreach (array_keys($map) as $issue2) {
                                    if (isset($relatedIssues[$issue2][$issue1])) {
                                        $mutuals[min($issue1, $issue2)] = max($issue1, $issue2);
                                    } else {
                                        $nonMutuals[min($issue1, $issue2)] = max($issue1, $issue2);
                                    }
                                }
                            }
                        } elseif (key($messageTypes) == 'unrelated issue updates') {
                            $prefixMany = "Related issues updated:\n\n";
                            $maybeNot = " not";
                            foreach ($unrelatedIssues as $issue1 => $map) {
                                foreach (array_keys($map) as $issue2) {
                                    if (isset($unrelatedIssues[$issue2][$issue1])) {
                                        $mutuals[min($issue1, $issue2)] = max($issue1, $issue2);
                                    } else {
                                        $nonMutuals[min($issue1, $issue2)] = max($issue1, $issue2);
                                    }
                                }
                            }
                        }
                        foreach ($mutuals as $issue1 => $issue2) {
                            $commitMessages[] = "Issues #$issue1 and #$issue2 are$maybeNot related";
                        }
                        foreach ($nonMutuals as $issue1 => $issue2) {
                            $commitMessages[] = "Issues #$issue1 and #$issue2 are$maybeNot related";
                        }
                        if (count($commitMessages) == 1) {
                            $retVal = current($commitMessages);
                        } else {
                            $retVal = $prefixMany . "- " . join("\n- ", $commitMessages);
                        }
                    } else {
                        $retVal = $prefixMany . "- " . join("\n- ", $commitMessages);
                    }
                } else {
                    $retVal = $prefixMany . "- " . join("\n- ", $commitMessages);
                }
            } else {
                $retVal = $prefixMany . "- " . join("\n- ", $commitMessages);
            }
        }

    }

    return $retVal;
}

function toListString($values, $maxLen, $singular = 'item', $plural = 'items') {
    $retVal = "";
    $count = count($values);

    if ($count > 0) {
        foreach ($values as $key => $value) {
            if (strlen($retVal) > 0) {
                if ($count > 2) {
                    $retVal .= ',';
                }
                $retVal .= ' ';
                if ($key === array_key_last($values)) {
                    $retVal .= '& ';
                }
            }
            $retVal .= maybeQuote($value);
        }

        if (strlen($retVal) > $maxLen) {
            //printf("strlen($retVal) = %d > $maxLen\n", strlen($retVal));
            $retVal = "$count " . ($count > 1 ? $plural : $singular);
        }

    }

    return $retVal;
}

function maybeQuote($value) {
    return preg_match('/\s(?![A-Z])|^[^A-Z0-9]/', $value) ? "\"$value\"" : $value;
}

function gitCommit($gitRepoDir, &$gitCommitCount, $message, $allowEmpty = false) {
    printf("commit: $message\n");
    $cwd = getcwd();
    chdir($gitRepoDir);

    try {
        systemOrExit("git add wip");
        if ($allowEmpty) {
            systemOrExit("git commit -m '$message' --allow-empty ");
        } else {
            systemOrExit("git commit -m '$message'");
        }

        $gitCommitCount++;
        if ($gitCommitCount >= 100) {
            $gitCommitCount = 0;
            systemOrExit("git gc");
        }
    } finally {
        chdir($cwd);
    }
}

function systemOrExit($command) {
    exec($command, $out, $retVal);
    if ($retVal !=  0) {
        printf("`%s` failed with exit code %d:\nSTDOUT: ", $command, $retVal);
        printf(join("\nSTDOUT: ", $out) . "\n");
        $e = new Exception();
        printf($e->getTraceAsString());
        throw $e;
        //exit($retVal);
    }
}

function readInfo($issueDir) {
    //printf("READ: $issueDir/technique-info.yaml\n");
    //debug_print_backtrace();
    return yaml_parse(file_get_contents("$issueDir/technique-info.yaml"));
}

function writeInfo($fieldsConfig, $issueDir, $info, $writeCommentsAndBlanks) {
    $fieldGroups     = $fieldsConfig['groups'];
    $fieldTypes      = $fieldsConfig['types'];
    $fieldsOrder     = $fieldsConfig['order'];
    $fieldComments   = $fieldsConfig['comments'];

    $ignoreFields = [
        'field',
        'ClonedFromKey',
        'attachments',
        'AttachmentsFromHistory',
        'Assignee',
        'Description',
        'Tests',
    ];

    $writeComments = $writeCommentsAndBlanks;

    $seenGroups = ['normal'];
    $seenFields = $ignoreFields;

    $issueKey = basename($issueDir);
    $issueNum = preg_replace('/.*-(\d+)$/', '\1', $issueKey);
    $issueNum = sprintf("%d", $issueNum);

    @mkdir($issueDir, 0777, true);
    $handle = fopen("$issueDir/technique-info.yaml", "w");

    //print_r($info);

    foreach ($fieldsOrder as $newFieldName => $fieldNames) {
        foreach ($fieldNames as $fieldName) {
            $fieldIsList = str_starts_with($fieldTypes[$newFieldName], 'List');
            $fieldGroup = $fieldGroups[$newFieldName];
            if ($fieldName == 'Issue Number') {
                writeYamlField($fieldComments, $handle, 'Issue Number', $issueNum, $writeComments);
                $seenFields[] = $fieldName;
            } elseif (key_exists($fieldName, $info)) {
                $seenFields[] = $fieldName;
                $value = $info[$fieldName];
                if ($value != null) {
                    // write blanks before:
                    // - Title (Summary)
                    // - List types
                    // - the first occurrence of a field in one of the non-"normal" groups
                    if (true
                        && $writeCommentsAndBlanks
                        && (false
                            || $newFieldName == 'Title'
                            || $fieldIsList
                            || !in_array($fieldGroup, $seenGroups)
                        )
                    ) {
                        fwrite($handle, "\n");
                        if ($fieldGroup == 'timestamp') {
                            // also write comment before timestamps group
                            fwrite($handle, '# Timestamps. See /wip/README.md');
                        }
                    }
                    $seenGroups[] = $fieldGroup;

                    if ($fieldIsList) {
                        if (is_array($value)) {
                            $values = $value;
                        } else {
                            $values = preg_split('/,\s*/', $info[$fieldName]);
                        }
                        writeYamlField($fieldComments, $handle, $fieldName, $values, $writeComments);
                    } else {
                        writeYamlField($fieldComments, $handle, $fieldName, $info[$fieldName], $writeComments);
                    }
                }
            }
        }
    }

    //print_r($seenFields);

    $fields = array_keys($info);
    foreach ($fields as $fieldName) {
        if (!in_array($fieldName, $seenFields)) {
            print("\nField missed writing info in $issueKey: $fieldName\n");
            print_r($fieldsOrder);
            print_r($seenFields);
            printf("\n");
        }
    }
}

function writeYamlField($fieldComments, $handle, $name, $value, $writeComments) {
    $line = is_array($value) ? "$name:" : "$name: " . yamlEscape($value);
    if ($writeComments && isset($fieldComments[$name])) {
        $line .= str_repeat(" ", max(1, 39 - strlen($line)));
        $line .= '# ' . $fieldComments[$name];
    }
    $line .= "\n";
    fwrite($handle, $line);
    if (is_array($value)) {
        foreach ($value as $v) {
            if ($v != null) {
                $v = yamlEscape($v);
                fwrite($handle, "    - $v\n");
            }
        }
    }
}

function yamlEscape($value) {
    if ($value == null) {
        printf("Null value\n");
        debug_print_backtrace();
        $value = "";
    }
    $tmp = preg_replace('/[][?:,{}#&*!|>%@`"’<+' . "'" . 'a-zA-Z\/0-9()_=. – -]/', '', $value);
    if (strlen($tmp) > 0) {
        printf("UNHANDLED CHARACTERS: '$tmp' from '$value'\n");
    }
    if (preg_match('/"/', $value) && preg_match("/'/", $value)) {
        printf("UNHANDLED BOTH QUOTES: in '$value'\n");
    //} elseif (preg_match('/"/', $value)) {
    //    printf("UNHANDLED DOUBLE QUOTES: in '$value'\n");
    } elseif (preg_match('/[][?:,{}#&*!|>"%@`-]/', $value)) {
        $value = "'$value'";
    }
    /*
    $value = yaml_emit($value, YAML_UTF8_ENCODING, YAML_LN_BREAK);
    $value = preg_replace('/^---\s+/', '', $value);
    $value = preg_replace('/\n[.]{3}\n$/', '', $value);
    */
    return $value;
}

function writeMarkdown($j2mDir, $issueDir, $name, $value, $timestamp) {
    $issueKey = basename($issueDir);
    $file = "$issueDir/$name.md";
    $issueNum = preg_replace('/.*-(\d+)$/', '\1', $issueKey);
    $tmpName = "PDFUA-$issueNum-$name-$timestamp.txt";
    $markdown = exportAndMarkdown($j2mDir, $tmpName, $value);
    if (substr($markdown, -1) != "\n") {
        $markdown .= "\n";
    }
    file_put_contents($file, $markdown);
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
                $mention = "Jira user " . $author['name'];
            } else if (isset($author['name'])) {
                $mention = "Jira user " . $author['key'];
            } else {
                $mention = "unknown Jira user ";
            }
        } else {
            $mention = "Jira user " . $author;
        }
    }

    if (str_contains($mention, 'Jira')) {
        if (is_array($author)) {
            printf("Mention says Jira:\n");
            print_r($author);
        } else {
            printf("Mention says Jira: '$author'\n");
        }
    }

    return $mention;
}
