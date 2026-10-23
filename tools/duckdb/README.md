# DuckDB CLI (for the Overture Maps Places source)

- File: `duckdb.exe`, DuckDB **v1.4.5 (LTS, "Andium")**, Windows amd64.
- Downloaded 23 Oct 2026 from the official page https://duckdb.org/install/ (LTS), URL
  `https://install.duckdb.org/v1.4.5/duckdb_cli-windows-amd64.zip`.
- SHA256 of the zip, verified against the DuckDB download page: `d51e5368ac9dc1645df0cf08197cdb6e9dfee38369d732cfb719d63f2c79708b`.
- Licence: MIT (DuckDB Foundation). Requires the Microsoft Visual C++ runtime (present on this PC).
- Used by `App\Services\Discovery\Adapters\OverturePlacesAdapter` to read Overture's public GeoParquet release
  (`s3://overturemaps-us-west-2/release/<release>/theme=places/type=place/*`, anonymous, free) into
  `storage/app/overture/<release>/*.parquet`. The `httpfs` extension is installed on first use into `%USERPROFILE%\.duckdb`.
- To move the binary elsewhere set `OVERTURE_DUCKDB_BIN` in `.env`.
