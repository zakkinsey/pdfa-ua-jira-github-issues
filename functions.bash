#!/bin/bash

set -eu

mkdir -p "$dataDir"

getProjectNodeId() {
	projectName="$1"
	
	file="$dataDir"/projects.graphql

	if ! [ -f "$file" ] ; then
		bash get-projects.bash "$githubUser"
	fi

	  jq .data.user.projectsV2.nodes.[] "$file" \
	| jq "select(.title == \"@$projectName\")" \
	| jq -r .id
}

getFieldNodeId() {
	projectName="$1"
	fieldName="$2"
	
	file="$dataDir"/fields.json

	if ! [ -f "$file" ] ; then
		bash get-fields.bash "$projectName"
	fi

	  jq .data.node.fields.nodes.[] "$file" \
	| jq "select(.name == \"$fieldName\")" \
	| jq -r .id
}
