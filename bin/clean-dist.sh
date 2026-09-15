#!/bin/bash
set -eo pipefail
curl -fsSL https://raw.githubusercontent.com/emboldagency/.github/master/scripts/clean-dist.sh | bash -s -- "$@"
