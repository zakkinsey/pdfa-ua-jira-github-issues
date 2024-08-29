#!/bin/bash

if [ -z "$1" -o -z "$2" ] ; then
	echo 'Error: project or issue node id unspecified.'
	echo 'Usage: add-issue-to-project.bash <project-node-id> <issue-node-id>'
	exit 1
fi

set -eu

. .env
. functions.bash

projectNodeId="$1"
issueNodeId="$2"

gh api graphql -f query='
  mutation {
    addProjectV2ItemById(input: {projectId: "'"$projectNodeId"'" contentId: "'"$issueNodeId"'"}) {
      item {
        id
      }
    }
  }'
