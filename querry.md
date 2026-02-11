now this what i want to create

i want to have new php file and new data table
name  add_contracts.php

i want to create contracts
where 

1. select client from clients data base
make it dynamic
when typing like 2 characted show all recommended clients base on company_name and main_signatory,

2. it has contract number
the contract number system is auto generated base on this

RCN-2026-G001-000001
RNT = short for rental
2026 = as year created
G001 = client was GOVERNMENT make it G, if was PRIVATE make it P, for number if G001 was exits make it G002 make it also for P, but if the year change make it back to 001
000001 = as count of all contracts this was autoincrement, continues regardless of year or government or private


3. dropdown for 'TYPE OF CONTRACT'(required)
   *UMBRELLA
   *SINGLE CONTRACT

   **add a questionaire for the user , the question is Does it have colored machines? with to box YES or NO, **
4. mono_rate (required)
5. color_rate(if checked NO hide this, if YES show this and make required)
6. excess_monorate (required)
7. excees_colorrate (if checked NO hide this, if YES show this and make required)
8. mincopies_mono (required)
9. mincopies_color (if checked NO hide this, if YES show this and make required)
10. spoilage (required)(in percent)
11. collection processing period (required)(this is number )
12. collection_date (make this HIDDEN AND NULL temporarily if the contract is private, but if the contract is government make it editable required)
12. vatable (required)(YES, NO dropdown)
13. upload contract (optional)(pdffiles allow mutiple)
**upload directory /uploads/contracts
14. status (ACTIVE,INACTIVE for first time input make it ACTIVE)
15. datecreated (actual date of creation)
16. createdby (make it NULL for the moment)


after insertion to the database
get the contract_id that was inserted

then it will go to another page for adding machine details that was connected to the contract
create a new table for machine details that was connected to the contract
name it contract_machines

contract_machines table
1. id (primary key, auto increment)
2. contract_id 
3. client_id (from clients table)
4. machine_type (dropdown MONOCHROME, COLOR) 
5. machine_model
6. machine_brand
7. machine_serial_number
8. machine_number
9. mono_meter_start
10. color_meter_start (only show if machine type is COLOR)
**need the installation address (this is the address where the machine will be installed, it can be different from the client's address)
11. building number
12. street name
13. barangay
14. city
15. zone
16. reading_date
17. status (ACTIVE, INACTIVE for first time input make it ACTIVE)
18. datecreated (actual date of creation)
19. createdby (make it NULL for the moment)


now this is how it works

if the TYPE OF CONTRACT is SINGLE CONTRACT
the user can only add one machine details

if the TYPE OF CONTRACT is UMBRELLA
the user can add multiple machine details

when adding barangay, and city 
Get zone based on barangay and city using geographical proximity using latitude and longitude from the zoning_zone table
the system will automatically get the zone number and area center from the zoning_zone table based on the
geographical proximity of the barangay and city entered by the user in the machine details form. The system will 
calculate the distance between the entered location and the locations in the zoning_zone table, and assign the zone 
number and area center of the closest match to the machine details being added.

also it will also get the reading date based on the zoning_zoe table column reading_date, 
**note if clients classification is GOVERNMENT 
make the reading date editable, but if the clients classification is PRIVATE make the reading date not editable and it will automatically 
get the reading date based on the zoning_zone table column reading_date, which is based on the geographical proximity of the entered 
location to the locations in the zoning_zone table. 
**remember that the reading_date value is number not date, if the value of reading_date from zoning_zone table is 3, 
the insert the 3 in reading_date column in contract_machines table**

now if it single contract and private
since we have a auto generated reading_date
based on the collection processing period
the system will automatically calculate the collection_date by adding the collection processing period to the reading_date, 
take note that it was calendar days of 30 days.
(sample if reading_date is 3 and collection processing period is 30, the system will calculate the collection_date by 
adding 30 days to the reading_date, so the collection_date will be 33 since theres no 33 in calendar days, the system will 
automatically convert the 33 to 3 and add 1 month to the current month, so the collection_date will be 3 of the next month.
so collection_date is number not a date)

if it is umbrella contract and private
since we have a auto generated reading_date
and since it was multiple machine, with sometimes falls on different zone
there a probability that the reading_date will be different for each machine details added, so the system will 
automatically calculate the collection_date on which machine reading_date has the highest reading_date, 
if the the system get the highest reading_date, it will automatically calculate the collection_date by adding the collection processing period to 
the highest reading_date, take note that it was calendar days of 30 days.
(sample if the highest reading_date is 3 and collection processing period is 30, the system will calculate the collection_date by 
adding 30 days to the reading_date, so the collection_date will be 33 since theres no 33 in calendar days, the system will 
automatically convert the 33 to 3 and add 1 month to the current month, so the collection_date will be 3 of the next month.
so collection_date is number not a date)

and after getting the collection date 
it will update the contract table column collection_date with the calculated collection_date(nunmber not date) 

additional 

this my config.php file
<?php
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'cpirental';

$conn = new mysqli($host, $username, $password, $database);

if ($conn->connect_error) {
    die("Connection failed: " . $conn->connect_error);
}


?>

dont make folders or subfolders