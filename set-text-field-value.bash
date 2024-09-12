#!/bin/bash

if [ -z "$4" ] ; then
    echo 'Error: missing parameter'
    echo 'Usage: '"$(basename "$0")"' <project-node-id> <item-node-id> <field-node-id> <value>'
    exit 1
fi

set -eu

. .env
. functions.bash

projectNodeId="$1"
itemId="$2"
fieldId="$3"
value="$4"

gh api graphql -f query='
mutation {
  updateProjectV2ItemFieldValue(
    input: {
      projectId: "'"$projectNodeId"'"
      itemId: "'"$itemId"'"
      fieldId: "'"$fieldId"'"
      value: {
        text: "'"$value"'"
      }
    }
  ) {
    clientMutationId
  }
}
'
