Using php and docker compose I want to pull JSON information from the https://api.weather.gov/alerts site and store the JSON return as records in a sqlite database called "alerts".

Uns PHPDocBlock for any functions created

Locate a DB schema for the api and create the database fields from it.

After receining the api data in JSON form parse the records into the sqlite db in the correct fields from the schema.  

In the docker compose I want to have a service called alerts that preforms all this and anoter service that uses the image for SQLiteBrowser and another service for Dozzle logging.

Use best coding practices and rate limit the api pull to no more than 4 pulls per minute.

Use a useragent header for the api pull that concantonates the repo name, version, and my email.

Set up environmental and config variables for all necessary variables.

Use best security practices including and sqlite.

Set up full descripting logging and error handling to te Dozzle service.

Log all progress to Dozzle.

Setup debugging mode as default but later allow production logging.

Create a README.md in the main directory and keep it updated

Create the copilot-instructions.md file

Create a INSTALL.md file int he main directory

Create a /documentation folder and keep all other documents in it.

Create documentation for any functions created in /documents
