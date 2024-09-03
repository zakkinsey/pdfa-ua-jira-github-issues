<?php

/**
 * Jira to Github Migration
 *
 * @example
 *
 * $ php import_tickets.php DDC
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

$githubOrg = getenv('GITHUB_ORG');
$githubRepo = $projects[$project];
$githubRepoUrl = "https://github.com/$githubOrg/$githubRepo/";
$repoIssuesUrl = "$githubRepoUrl" . 'issues/';

$count = 0;

@mkdir("data/" . $project, 0777);

$knownIssueTypes = explode(',', getenv('ISSUE_TYPES'));
$knownAssigneesMap = json_decode(getenv('ASSIGNEES'), true);

$dataDir = "data";
$projectDataTag = "$dataDir/$project";
$jiraExportDir = "$projectDataTag/jira-export";

$githubProjectName = getenv('githubProjectName');
shell_exec('./get-projects.bash ' . getenv('githubUser'));
$projectsDef = json_decode(file_get_contents("$dataDir/projects.json"), true);
$projectNodeId = null;
foreach ($projectsDef['data']['user']['projectsV2']['nodes'] as $projectDef) {
    if ($projectDef['title'] == "@$githubProjectName") {
        $projectNodeId = $projectDef['id'];
    }
}

shell_exec("./get-fields.bash $githubProjectName");

$files = scandir($jiraExportDir);

$maxIssueId = 0;
$issueIds = [];
foreach ($files as $file) {
    if (is_dir("$projectDataTag/$file")) continue;
    $issueId = preg_replace("/(?:jira-)?$project-(\\d+)\\.json/", '$1', $file);
    $maxIssueId = max($maxIssueId, $issueId);
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
    "components"                     => "Component/s",
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

$fieldNamesToMigrate = [
//    "Title",
//    "Assignees",
    "Status",
//    "Labels",
//    "Linked pull requests",
//    "Milestone",
//    "Repository",
//    "Reviewers",
    "Component/s",
    "Example type",
    "PDF/UA Parts",
    "Use cases",
    "Pass / Fail",
    "Tests",
    "UA Technique Tag",
    "Keywords",
    "Matterhorn Protocol",
    "WCAG 2.2 Success Criteria",
    "WCAG 2.2 PDF Technique",
    "Marked-content sequences",
    "Structure Types",
    "PAC 2021 Checked",
    "PAC 2024 Checked",
    "veraPDF UA Checked",
    "Arlington Checked",
    "CommonLook PDF Checked",
    "Acrobat Accessibility Checked",
    "Acrobat Preflight UA Checked",
    "PAC 3 Checked",
];

$fieldKeysMap = array();
$firstWordOnlyField = array();

foreach ($fieldNamesToMigrate as $fieldName) {
    $found = false;
    foreach ($fieldNamesMap as $key => $value) {
        if ($value == $fieldName) {
            $fieldKeysMap[$value] = $key;
            $firstWordOnlyField[$value] = false;
            $found = true;
            break;
        }
    }

    if (!$found) {
        printf("No key for $fieldName\n");
    }
}

$firstWordOnlyField["Marked-content sequences"] = true;
$firstWordOnlyField["Structure Types"] = true;

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
    'Metadata Review' => 'Meatadata Review',
    'Accepted' => 'Accepted',
    'Web Testing' => 'Web Testing',
    'Published' => 'Published',
];

print_r($fieldKeysMap);

$githubFieldDefs = json_decode(file_get_contents("$dataDir/fields.json"), true);

$fieldIds = array();
$optionIds = array();

foreach ($githubFieldDefs['data']['node']['fields']['nodes'] as $fieldDef) {
    $fieldDefId = $fieldDef['id'];
    $fieldDefName = $fieldDef['name'];
    $fieldIds[$fieldDefName] = $fieldDefId;
    if (isset($fieldDef['options'])) {
        $fieldDefOptions = array();
        foreach ($fieldDef['options'] as $optionDef) {
            $optionId = $optionDef['id'];
            $optionName = $optionDef['name'];
            $fieldDefOptions[$optionName] = $optionId;
        }
        $optionIds[$fieldDefName] = $fieldDefOptions;
    }
}

$count = 0;
for ($issueId = 1; $issueId <= $maxIssueId; $issueId++) {
    $issueKey = $project . '-' . $issueId;
    $file = "$jiraExportDir/jira-$issueKey.json";

    if (is_file($file)) {
        printf("\n");
        $issueUrl = "$repoIssuesUrl$issueId\n";
        printf($issueUrl);

        $issueNodeOut = shell_exec("./get-node-id-from-url.bash $issueUrl");
        $issueNode = json_decode($issueNodeOut, true);
        $issueNodeId = $issueNode['data']['resource']['id'];

        $addProjectCmd = "./add-issue-to-project.bash $projectNodeId $issueNodeId";
        $projectItem = json_decode(shell_exec($addProjectCmd), true);

        $itemNodeId = $projectItem['data']['addProjectV2ItemById']['item']['id'];

        $issue = json_decode(file_get_contents($file), true);
//        print_r($issue);
        $fields = $issue['fields'];

        if ($fields != null) {
            foreach ($fieldKeysMap as $fieldName => $fieldKey) {
                $fieldNodeId = $fieldIds[$fieldName];
                if (isset($fields[$fieldKey])) {
                    $field = $fields[$fieldKey];
                    if ($field != null) {
                        $value = '';
                        //printf("$fieldName ($fieldKey) is type %s.\n", gettype($field));
                        if (is_scalar($field)) {
                            $value = $field;
                            //printf("\t$fieldName = '$value'\n");
                        } else if (isset($field['value'])) {
                            $value = $field['value'];
                            //printf("\t$fieldName = '$value'\n");
                        } else if (isset($field['name'])) {
                            $value = $field['name'];
                            if ($fieldName == 'Status') {
                                //printf("\t$fieldName = '$value'\n");
                                $value = $statusMap[$value];
                            }
                            //printf("\t$fieldName = '$value'\n");
                        } else if (isset($field[0])) {
                            $value = '';
                            foreach ($field as $fieldItem) {
                                if ($value != '') {
                                    $value .= ' ; ';
                                }
                                //printf("Array $fieldName item is type %s.\n", gettype($fieldItem));
                                if (is_scalar($fieldItem)) {
                                    $value .= $fieldItem;
                                    //printf("\tArray $fieldName = '$value'\n");
                                } else if (isset($fieldItem['value'])) {
                                    $itemValue = $fieldItem['value'];
                                    if ($firstWordOnlyField[$fieldName]) {
                                        $itemValue = preg_replace('/ .*/', '', $itemValue);
                                        $value .= $itemValue;
                                    }
                                    //printf("\tArray $fieldName = '$value'\n");
                                } else if (isset($fieldItem['name'])) {
                                    $value .= $fieldItem['name'];
                                    //printf("\tArray $fieldName = '$value'\n");
                                } else {
                                    printf("\tArray $fieldName has no value or name entry:\n");
                                    print_r($field);
                                    printf("\n\n");
                                }
                            }
                        } else {
                            printf("\t$fieldName has no value or name entry:\n");
                            print_r($field);
                            printf("\n\n");
                        }
                        if (isset($value) && $value != null && $value != '') {
                            if ($fieldName == 'Tests') {
                                $value = toMarkdown($value);
                                $sq = "'";
                                $dq = '"';
                                $value = preg_replace("/'/", "$sq$dq$sq$dq$sq", $value);
                            } else {
                                if (isset($optionIds[$fieldName])) {
                                    $optionId = $optionIds[$fieldName][$value];
                                    $setFieldCmd = "./set-select-field-value.bash $projectNodeId $itemNodeId $fieldNodeId $optionId";
                                } else {
                                    $setFieldCmd = "./set-text-field-value.bash $projectNodeId $itemNodeId $fieldNodeId '$value'";
                                }
                                printf("\t\t$setFieldCmd\n");
                                print_r(shell_exec($setFieldCmd));
                                printf("\n");
                                sleep(1);
                            }
                        }
                    } else {
                        //printf("\t$fieldName is null\n");
                    }
                } else {
                    //printf("\t$fieldName is not set\n");
                }
            }
        }
    }

}
