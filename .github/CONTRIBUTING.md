# CONTRIBUTING

Contributions are welcome, and are accepted via pull requests.
Please review these guidelines before submitting any pull requests.

## Guidelines

* Ensure that your code is formatted by running `composer fmt`.
* Ensure that your code follows the package coding standard by running `composer lint`.
* Ensure static analysis pass by running `composer analyse`.
* Ensure tests pass by running `composer test`.
* Ensure full code coverage by running `composer test:coverage` and that every mutant is killed by running `composer test:mutate` (both require Xdebug).
* Declare the classes each test file covers with `covers()`, otherwise they won't be mutated.
* Send a coherent commit history, making sure each individual commit in your pull request is meaningful, make sure commit messages follow [conventional commits](https://conventionalcommits.org).
* You may need to [rebase](https://git-scm.com/book/en/v2/Git-Branching-Rebasing) to avoid merge conflicts.


## Process

1. Fork the package
2. Create a new branch
3. Code, test, analyse, lint, format, commit and push
4. Open a pull request detailing your changes. Make sure to follow the [template](PULL_REQUEST_TEMPLATE.md)

## Setup

Clone your fork and then install the dependencies:
```bash
composer install
```

## Format

Check your code formatting:
```bash
composer fmt:check
```

Format your code:
```bash
composer fmt
```

## Lint

Lint your code:
```bash
composer lint:check
```

Lint and fix your code:
```bash
composer lint
```

## Static analysis

Analyse your code:
```bash
composer analyse
```

## Tests

Test your code:
```bash
composer test
```

Test your code with coverage (minimum 100%):
```bash
composer test:coverage
```

Run mutation testing (minimum score 100%):
```bash
composer test:mutate
```

Run the browser tests of the script, the Blade components and the endpoint. Install [Playwright](https://playwright.dev) and Chromium first:
```bash
npm install
npx playwright install chromium
composer test:browser
```
