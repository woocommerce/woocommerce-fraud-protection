#!/usr/bin/env bash
#
# Smoke test runner for WooCommerce Fraud Protection startup hardening.
#
# Each scenario file under scenarios/ runs as an isolated PHP process with
# minimal WP/WC stubs. A scenario is expected to print "OK\n" on stdout and
# exit 0 on success. Any failed assertion exits non-zero.
#
# Usage: tests/php/smoke/run.sh [-v]
#   -v  verbose: stream stdout/stderr from every scenario.
#
set -u
DIR=$(cd "$(dirname "$0")" && pwd)

verbose=0
if [ "${1:-}" = "-v" ]; then
	verbose=1
fi

pass=0
fail=0
failed_names=""

for test in "$DIR"/scenarios/*.php; do
	name=$(basename "$test" .php)
	if [ "$verbose" -eq 1 ]; then
		echo "--- running $name ---"
		if php "$test"; then
			echo "PASS: $name"
			pass=$((pass + 1))
		else
			echo "FAIL: $name"
			fail=$((fail + 1))
			failed_names="$failed_names $name"
		fi
	else
		output=$(php "$test" 2>&1)
		status=$?
		if [ "$status" -eq 0 ]; then
			echo "PASS: $name"
			pass=$((pass + 1))
		else
			echo "FAIL: $name"
			echo "$output"
			fail=$((fail + 1))
			failed_names="$failed_names $name"
		fi
	fi
done

echo
echo "Smoke summary: $pass passed, $fail failed"
if [ "$fail" -gt 0 ]; then
	echo "Failed scenarios:$failed_names"
	exit 1
fi
exit 0
