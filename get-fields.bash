#!/bin/bash

if [ -z "$1" ] ; then
	echo 'Error: project name unspecified.'
	echo 'Usage: get-fields.bash <project-name>'
	exit 1
fi

set -eu

. .env
. functions.bash

projectName="$1"
projectNodeId="$(getProjectNodeId "$projectName")"

gh api graphql -f query='
  query{
  node(id: "'"$projectNodeId"'") {
    ... on ProjectV2 {
      fields(first: 100) {
        nodes {
          ... on ProjectV2Field {
            id
            name
          }
          ... on ProjectV2IterationField {
            id
            name
            configuration {
              iterations {
                startDate
                id
              }
            }
          }
          ... on ProjectV2SingleSelectField {
            id
            name
            options {
              id
              name
            }
          }
        }
      }
    }
  }
}' > "$dataDir"/fields.json
