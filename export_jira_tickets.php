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

$startAt = isset($argv[2]) ? (int)($argv[2]) : 0;
$githubRepository = $projects[$project];
$githubHeaders = ['User-Agent: ' . getenv('GITHUB_ORG') . ' Jira Migration', 'Authorization: token ' . getenv('GITHUB_TOKEN')];
$jiraHeaders = ['Authorization: Basic ' . base64_encode(sprintf('%s:%s', getenv('JIRA_USER'), getenv('JIRA_TOKEN')))];

$client = new \Buzz\Browser();

$response = $client->get('https://api.github.com/repos/' . getenv('GITHUB_ORG') . '/' . $githubRepository . '/milestones?state=all&per_page=100', $githubHeaders);
if ($response->getStatusCode() !== 200) {
    printf("Could not fetch existing Github Milestones\n");
    var_dump($response->getContent());
    exit(3);
}

$existingMilestones = [];
foreach(json_decode($response->getContent(), true) as $existingMilestone) {
    $existingMilestones[$existingMilestone['title']] = $existingMilestone['number'];
}

$count = 0;

$dataDir = "data";
$projectDataTag = "$dataDir/$project";
$jiraExportDir = "$projectDataTag/jira-export";
@mkdir($dataDir, 0777);
@mkdir($projectDataTag, 0777);
@mkdir($jiraExportDir, 0777);

if (!is_dir($jiraExportDir)) {
    printf("Could not create directory: '$projectDataTag'\n");
    exit(2);
}

$knownIssueTypes = explode(',', getenv('ISSUE_TYPES'));
$knownAssigneesMap = json_decode(getenv('ASSIGNEES'), true);

while (true) {
    $response = $client->get(getenv('JIRA_URL') . "/rest/api/2/search?jql=" . urlencode("project = $project ORDER BY created ASC") . "&fields=" . urlencode("*all") . "&startAt=" . $startAt . "&expand=changelog", $jiraHeaders);

    if ($response->getStatusCode() !== 200) {
        printf("Could not fetch versions of project '$project'\n");
        printf($response->getStatusCode());
        exit(2);
    }

    $issues = json_decode($response->getContent(), true);
    file_put_contents($projectDataTag . ".json", json_encode($issues, JSON_PRETTY_PRINT));

    if (count($issues['issues']) === 0) {
        printf("Exported %d issues from Jira into %s/ folder.\n", $count, $jiraExportDir);
        return;
    }
    $count += count($issues['issues']);

    foreach ($issues['issues'] as $issue) {
        file_put_contents("$jiraExportDir/jira-{$issue['key']}.json", json_encode($issue, JSON_PRETTY_PRINT));
        printf("Processed issue: %s (Idx: %d)\n", $issue['key'], $startAt);
        $startAt++;
    }

    printf("Completed batch, continuing with start at %d\n", $startAt);
}

function mentionName($name) {
    global $knownAssigneesMap;

    if (isset($knownAssigneesMap[$name])) {
        return '@' . $knownAssigneesMap[$name];
    }
    return $name;
}
