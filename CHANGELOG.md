# Changelog

## Unreleased

### Fixed

- Reject missing or unknown response answer types with `JevException` instead of interpreting them as noul answers.
- Require noul probabilities to be finite numbers from 0 to 1. Missing values, strings (including numeric strings), booleans and out-of-range values no longer become apparently valid answers.
