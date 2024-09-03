#!/bin/bash

if [ -z "$1" ] ; then
	echo 'Error: github username unspecified.'
	echo 'Usage: get-projects.bash <github-username>'
	exit 1
fi

set -eu

. .env
. functions.bash

githubUser="$1"

gh api graphql -f query='
  query{
    user(login: "'$githubUser'") {
      projectsV2(first: 20) {
        nodes {
          id
          title
        }
      }
    }
  }' > data/projects.json
