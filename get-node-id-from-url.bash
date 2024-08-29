#!/bin/bash

if [ -z "$1" ] ; then
	echo 'Error: Resource URL unspecified.'
	echo 'Usage: get-node-id-from-url.bash <resource-urle>'
	exit 1
fi

set -eu

. .env
. functions.bash

resourceUrl="$1"

gh api graphql -f query='
query {
  resource(url: "'"$resourceUrl"'") {
    ... on Issue {
      id
      projectItems(first: 100) {
        nodes {
          itemId: id
          project {
            id
          }
        }
      }
    }
    ... on Repository {
      id
    }
  }
}
'
