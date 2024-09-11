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
$client = new \Buzz\Browser();

if (!isset($projects[$project])) {
    printf("Unknown project: $project\n");
    exit(2);
}

$githubRepository = $projects[$project];
$githubHeaders = [
    'User-Agent: ' . getenv('GITHUB_ORG') . ' Jira Migration',
    'Authorization: token ' . getenv('GITHUB_TOKEN'),
    'Accept: application/vnd.github.golden-comet-preview+json'
];
$githubImportUrl = 'https://api.github.com/repos/' . getenv('GITHUB_ORG') . '/' . $githubRepository . '/import/issues';

$dataDir = "data";
$projectDataTag = "$dataDir/$project";

$files = scandir($projectDataTag);

$maxIssueId = 0;
$issueIds = [];
foreach ($files as $file) {
    if (is_dir("$projectDataTag/$file")) continue;
    $issueId = preg_replace("/$project-(\\d+)\\.json/", '$1', $file);
    $maxIssueId = max($maxIssueId, $issueId);
    array_push($issueIds, intval($issueId));
}
sort($issueIds);

$count = 0;
for ($issueId = 1; $issueId <= $maxIssueId; $issueId++) {
    $issueKey = $project . '-' . $issueId;
    $file = "$projectDataTag/$issueKey.json";

    if (is_file($file)) {
        $issue = json_decode(file_get_contents($file), true);

        printf("Creating real issue: $issueKey\n");
        createIssue($client, $githubImportUrl, $githubHeaders, $issue);
    } else {
        printf("Creating fake issue: $issueKey\n");
        $issueCreated = createIssue($client, $githubImportUrl, $githubHeaders, [
            'jiraKey' => $issueKey,
            'deleteIssue' => true,
            'issue' => [
                'title' => "$issueKey: fake issue to be deleted",
                'body' => "body of fake $issueKey issue to be deleted",
            ],
        ]);
        if ($issueCreated) {
            // TODO: delete fake issue
            // for the instant purpose, deleting fake issues manually is fine
        }
    }

}

function createIssue($client, $githubImportUrl, $githubHeaders, $issue) {
    $issueKey = $issue['jiraKey'];
    unset($issue['jiraKey']);
    $deleteIssue = isset($issue['deleteIssue']) && $issue['deleteIssue'];
    printf("Creating issue $issueKey... ");

    $response = $client->post($githubImportUrl, $githubHeaders, json_encode($issue));
    $ticketStatus = json_decode($response->getContent(), true);

    if ($response->getStatusCode() >= 400) {
        printf("Error posting $issueKey:\n");
        print_r($response);
        print_r($ticketStatus);
        return false;
    }

    sleep(1);

    while ($ticketStatus['status'] === 'pending') {
        $response = $client->get($ticketStatus['url'], $githubHeaders);
        $ticketStatus = json_decode($response->getContent(), true);

        if ($response->getStatusCode() != 200) {
            printf("Error getting status of $issueKey:\n");
            print_r($response);
            print_r($ticketStatus);
            return false;
        }

        sleep(1);
    }

    if ($ticketStatus['status'] === 'imported') {
        printf("Success!\n");
        if ($deleteIssue) {
            printf("Deleting fake issue: $issueKey\n");

            $issueUrl = $ticketStatus['issue_url'];

            $issueResponse = $client->post($issueUrl, $githubHeaders);
            $issueJSON = json_decode($response->getContent(), true);
            $issueNodeId = $issueJSON['node_id'];

            $deletionOut = shell_exec("./delete-issue.bash $issueNodeId");
        }
        return true;
    }

    if ($ticketStatus['status'] === 'failed') {
        printf("Import failed for $issueKey:\n");
        print_r($response);
        print_r($ticketStatus);
        return false;
    }

    printf("Unknown status for $issueKey:\n");
    print_r($response);
    print_r($ticketStatus);

    return false;
    // file_put_contents("data/" . $project . ".status.json", json_encode($ticketStatus, JSON_PRETTY_PRINT));

}
