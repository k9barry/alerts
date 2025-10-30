# Alerts Documentation

This directory contains comprehensive documentation for the Alerts weather notification system.

## Documentation Structure

### Root Level
- [INDEX.md](INDEX.md) - Complete documentation index with all links

### Directories

#### overview/
High-level system documentation:
- **ARCHITECTURE.md** - System design, components, patterns, data flow
- **CONFIGURATION.md** - Complete environment variable reference
- **DATABASE.md** - Schema, tables, queries, maintenance
- **RUNTIME.md** - Scheduler operation, execution flow, monitoring

#### scripts/
Executable script documentation:
- **migrate.md** - Database migrations
- **scheduler.md** - Continuous scheduler
- **oneshot_poll.md** - Single poll execution

#### src/
Source code documentation organized by component:
- Core configuration and initialization
- Database layer
- HTTP clients and rate limiting
- Logging setup
- Data access repositories
- Business logic services
- Scheduler commands
- Dependencies

## Getting Started

New users should read in this order:
1. [../README.md](../README.md) - Project overview
2. [../INSTALL.md](../INSTALL.md) - Installation guide
3. [overview/ARCHITECTURE.md](overview/ARCHITECTURE.md) - System design
4. [overview/CONFIGURATION.md](overview/CONFIGURATION.md) - Configuration options
5. [overview/RUNTIME.md](overview/RUNTIME.md) - How the scheduler works

## For Developers

Developers should additionally read:
1. [../README.DEV.md](../README.DEV.md) - Development setup
2. [src/](src/) - Component documentation for areas you're working on
3. [overview/DATABASE.md](overview/DATABASE.md) - Database schema

## Finding Documentation

### By Task
- **Setting up**: [../INSTALL.md](../INSTALL.md)
- **Configuring**: [overview/CONFIGURATION.md](overview/CONFIGURATION.md)
- **Understanding the code**: [INDEX.md](INDEX.md) → src/
- **Running the scheduler**: [scripts/scheduler.md](scripts/scheduler.md)
- **Database queries**: [overview/DATABASE.md](overview/DATABASE.md)
- **Adding features**: [overview/ARCHITECTURE.md](overview/ARCHITECTURE.md)
- **Troubleshooting**: Check relevant component doc or RUNTIME.md

### By Component
Use [INDEX.md](INDEX.md) to navigate to specific component documentation.

## Documentation Standards

All documentation follows these guidelines:
- **Format**: Markdown (*.md)
- **Structure**: Title, location, purpose, usage, examples
- **Code Examples**: Working, tested code snippets
- **Links**: Relative links to other documentation
- **Maintenance**: Updated when code changes

## Checking Documentation

Verify internal links are valid:
```sh
php scripts/check_docs_links.php
```

This checks:
- All .md files in documentation/
- Relative links to other markdown files
- Existence of referenced files
- Anchor links (heading targets)

## Contributing to Documentation

When modifying code:
1. Update relevant documentation in the same PR
2. Add new documentation for new components
3. Run link checker before committing
4. Update INDEX.md if adding new files

## External References

See [INDEX.md](INDEX.md) for links to:
- Weather.gov API documentation
- Pushover API documentation  
- ntfy documentation
- SQLite documentation
- PHP library documentation
