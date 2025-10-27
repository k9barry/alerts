Using php and docker compose I want to pull JSON information from the https://api.weather.gov/alerts/active site using a
scheduler service that checks for new alerts every X minutes default to 3 minutes 

Rate limit the api call to no more than X calls per minute default to 4

Create a table in alerts for user_data.  Include the fields first_name, Last_name, email, pushover_token, pushover_user,
same_array, ugc_array, latatude, longatude, alert_location

Parse the json return and store all the json fields in the sqlite db.  Specifically store the SAME and UGC codes as
arrays in their own fields

Store the JSON return as records in a sqlite database called "incoming_alerts".

Compare the incoming_alerts to the table of active_alerts and  add the alerts from the incoming_alerts that do not have a
matching alert already in active_alerts to the pending_alerts table

If the pending_alerts json data for same_array or ugc_array match the info in the table user_data sme_array or ugc_array
send the formatted info to the pushover.org api using the pushover_user and pushover_token.  Rate limit the sending to
pushover to once every 2 seconds

Copy the record to the table called sent_alerts with the datetime send and send status success or failure.  If failed
then retry for 3 times then fail.  Log the failure.

Replace the active_alerts with the incoming_alerts data

Using the scheduler service pull data from the weather.gov/alerts/active api every X minutes default to 3

Using the scheduler service run a vacuum on the DB every x hours default to 24

In the docker compose I want to have a service called alerts that preforms all this

Create a second service that uses the image for SQLiteBrowser 

Create a third service for Dozzle logging

Use best coding practices

Use a useragent header for the api pull that concatenates the repo name, version, and my email.

Set up environmental and config variables for all variables.

Use best security practices

Set up full descriptive logging using monolog/monolog with the IntrospectionProcessor and streaming to the Dozzle 
container

Create a README.md in the main directory and keep it updated

Create a INSTALL.md file in the main directory and keep it updated

Create a /documentation folder and keep all other documents in it.

Use PHPDocBlock and document the code heavily

Create a GUI to CRUD the user_data table 
