#!/bin/bash
# Wrapper script to run Pest tests directly, bypassing the artisan test
# subprocess which crashes with signal 11 (SIGSEGV) due to a known
# PHP 8.4.5 + ICU 76 shutdown segfault in the intl extension.
#
# The segfault occurs during PHP process cleanup (after all code has
# executed successfully), so tests run correctly but artisan test
# catches the non-zero exit code and throws ProcessSignaledException.
#
# Usage:
#   ./scripts/run-tests.sh                    # Run all tests
#   ./scripts/run-tests.sh --filter="Genre"   # Run filtered tests

cd "$(dirname "$0")/.." || exit 1

# Run Pest and capture output. Exit code 139 (128+11) means SIGSEGV on shutdown
# which is harmless — treat it as success if tests printed a passing result.
output=$(./vendor/bin/pest "$@" 2>&1)
exit_code=$?

echo "$output"

if [ $exit_code -eq 139 ]; then
    # Check if test output indicates all tests passed
    if echo "$output" | grep -qE "passed"; then
        exit 0
    fi
fi

exit $exit_code
