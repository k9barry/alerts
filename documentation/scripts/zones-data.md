# Weather Zones Data

## Overview

The alerts system uses weather zone data from the National Weather Service to allow users to subscribe to alerts for specific geographic areas.

## Data File

The zones data is stored in `/data/bp18mr25.dbx` in pipe-separated format with the following fields:

- **STATE**: Two character state abbreviation
- **ZONE**: Three character zone number
- **CWA**: Three character CWA ID (of the zone)
- **NAME**: Zone name
- **STATE_ZONE**: 5 character state + three character zone number
- **COUNTY**: County name
- **FIPS**: 5 character state-county FIPS code
- **TIME_ZONE**: Time zone of polygon (IANA format)
- **FE_AREA**: Feature Area (location in STATE)
- **LAT**: Latitude of centroid of the zone
- **LON**: Longitude of centroid of the zone

## Obtaining the Data

### Official Source

The official zones data can be downloaded from the National Weather Service:

```bash
curl -L "https://www.weather.gov/source/gis/Shapefiles/County/bp18mr25.dbx" \
  -o data/bp18mr25.dbx
```

### Sample Data

For testing and development, a sample dataset is provided that includes zones from several states:

```
STATE|ZONE|CWA|NAME|STATE_ZONE|COUNTY|FIPS|TIME_ZONE|FE_AREA|LAT|LON
IN|001|IND|Northern Indiana|IN001|Lake|18089|America/Chicago|NW|41.6|-87.3
IN|002|IND|Central Indiana|IN002|Marion|18097|America/Indianapolis|C|39.8|-86.1
IN|003|IND|Southern Indiana|IN003|Monroe|18105|America/Indianapolis|S|39.2|-86.5
OH|010|CLE|Northern Ohio|OH010|Cuyahoga|39035|America/New_York|N|41.5|-81.7
OH|020|ILN|Central Ohio|OH020|Franklin|39049|America/New_York|C|40.0|-83.0
MI|001|GRR|Western Michigan|MI001|Kent|26081|America/Detroit|W|42.9|-85.7
IL|001|LOT|Northern Illinois|IL001|Cook|17031|America/Chicago|N|41.9|-87.8
KY|001|LMK|Northern Kentucky|KY001|Jefferson|21111|America/New_York|N|38.2|-85.7
```

Save this content to `data/bp18mr25.dbx` to get started.

## Database Integration

The zones data is automatically loaded into the SQLite database during migration if:
1. The file `data/bp18mr25.dbx` exists
2. The zones table is empty

The migration script (`scripts/migrate.php`) handles this automatically on first run or when the zones table is empty.

## Indexes

The zones table has indexes on:
- `STATE` - for quick filtering by state
- `NAME` - for searching by zone name
- `STATE_ZONE` - for composite lookups

## Usage in User Management

Users can select multiple zones through the web interface at `/` (port 8080). The selected zone IDs are stored as a JSON array in the `ZoneAlert` field of the users table.
