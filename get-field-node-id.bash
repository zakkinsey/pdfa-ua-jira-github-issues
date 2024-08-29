#!/bin/bash

if [ -z "$1" -o -z "$2" ] ; then
	echo 'Error: project or field name unspecified.'
	echo 'Usage: get-field-node-id.bash <project-name> <field-name>'
	exit 1
fi

set -eu

. .env
. functions.bash

projectName="$1"
fieldName="$2"

getFieldNodeId "$projectName" "$fieldName"
