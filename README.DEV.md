Development README

This project runs inside Docker but can be developed on the host. This file explains common setup tasks for local development and IDE integration.

1) Ensure dependencies are installed (host or container)

- Option A (recommended for IDEs): Run `composer install` on the host to create `vendor/` visible to your IDE.
- Option B: Use the project's Docker container and configure your IDE to use the container's PHP interpreter.

2) IDE configuration

- If you run Composer on the host, your IDE (e.g., PhpStorm) will automatically index `vendor/` and Composer autoload mappings.
- If not, configure your IDE to use the project's Docker PHP interpreter (so it indexes the container's vendor/ path).

3) Line endings and shebangs

- This repo includes `.gitattributes` which enforces LF for shell scripts and scripts in `scripts/` and `docker/` to avoid Linux `env: 'bash\r'` shebang errors.

4) Quick development commands (host `cmd.exe` / Windows):

```powershell
# install dependencies on host (Windows cmd.exe equivalent shown)
composer install

# run the dev test script
php scripts/dev_test.php
```

5) Docker compose (if you want `vendor/` inside the container)

- The repository's `docker-compose.yml` mounts the project root into `/app` and will mount vendor into the container if present on the host. If you prefer container-only vendor, configure your IDE to use the container.

6) Troubleshooting editor warnings

- If your IDE reports missing namespaces for packages in `vendor/`, ensure either `vendor/` is present on the host or configure remote indexing with the container.

7) IDE-specific steps

- PhpStorm (recommended):
  - If `vendor/` is present on host: File -> Invalidate Caches / Restart to force re-index.
  - If you want remote indexing using Docker: Preferences -> PHP -> CLI Interpreter -> Add -> From Docker, select the project's container image. Then set that interpreter as the project interpreter and let PhpStorm index the remote autoloader.

- VS Code:
  - Install PHP Intelephense extension. Ensure the workspace has access to `vendor/` or configure remote containers (Remote - Containers extension) and open the project in the container.

8) Running tests

- PHPUnit is added as a dev dependency (see composer.json require-dev). To run tests locally after `composer install`:

```bash
./vendor/bin/phpunit --configuration phpunit.xml
```

- If you don't want to install PHPUnit on the host, use the provided lightweight smoke runner:

```bash
php scripts/run_unit_smoke.php
```

9) Docs link checker

- A small checker is available at `scripts/check_docs_links.php` to find broken internal markdown links. Run it with:

```bash
php scripts/check_docs_links.php
```
