### Bug fixes

- The demo of the README starts again: PostgreSQL 18 stopped at once, since it keeps its data in a folder of its own. A demo that you set up with PostgreSQL 17 can't be read by PostgreSQL 18; remove it with `docker compose -f demo/compose.yaml down --volumes` and run `bash demo/seed.sh` again.
