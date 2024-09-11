#!/bin/bash

if [ -z "$1"= ] ; then
	echo 'Error: issue node id unspecified.'
	echo 'Usage: delete-issuet.bash <issue-node-id>'
	exit 1
fi

set -eu

. .env

issueNodeId="$1"

gh api graphql -f query='
  mutation() {
    deleteIssue(input: {
      issueId: "'"$issueNodeId"'"
    }) {
      clientMutationId
    }
  }
'
