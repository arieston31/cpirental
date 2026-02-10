this is barangay_coordinates table structure
+--------------+------------------+------+-----+---------------------+------------------+
| Field        | Type             | Null | Key | Default             | Extra            |
+--------------+------------------+------+-----+---------------------+------------------+
| id           | int(11)          | NO   | PRI | NULL                | auto_increment   |
| barangay     | varchar(100)     | YES  | MUL | NULL                |                  |
| city         | varchar(100)     | YES  |     | NULL                |                  |
| latitude     | decimal(10,6)    | YES  |     | NULL                |                  |
| longitude    | decimal(10,6)    | YES  |     | NULL                |                  |
| zone_id      | int(11)          | YES  |     | NULL                |                  |
| last_updated | timestamp        | YES  |     | current_timestamp() |                  |
+--------------+------------------+------+-----+---------------------+------------------+

this is zone_reading_schedule table structure
+---------------+-----------+------+-----+---------------------+----------------+
| Field         | Type      | Null | Key | Default             | Extra          |
+---------------+-----------+------+-----+---------------------+----------------+
| id            | int(11)   | NO   | PRI | NULL                | auto_increment |
| zone_id       | int(11)   | NO   | MUL | NULL                |                |
| reading_date  | int(11)   | NO   |     | NULL                |                |
| machine_count | int(11)   | YES  |     | 0                   |                |
| created_at    | timestamp | YES  |     | current_timestamp() |                |
+---------------+-----------+------+-----+---------------------+----------------+


this is zoning_clients table structure
+----------------+------------------------------+------+-----+---------------------+----------------+
| Field          | Type                         | Null | Key | Default             | Extra          |
+----------------+------------------------------+------+-----+---------------------+----------------+
| id             | int(11)                      | NO   | PRI | NULL                | auto_increment |
| company_name   | varchar(200)                 | NO   |     | NULL                |                |
| classification | enum('GOVERNMENT','PRIVATE') | NO   |     | NULL                |                |
| contact_number | varchar(50)                  | NO   |     | NULL                |                |
| email          | varchar(100)                 | NO   | UNI | NULL                |                |
| status         | enum('ACTIVE','INACTIVE')    | NO   | MUL | ACTIVE              |                |
| created_at     | timestamp                    | YES  |     | current_timestamp() |                |
+----------------+------------------------------+------+-----+---------------------+----------------+


this is zoning_machine table structure
+--------------------+------------------------------+------+-----+---------------------+----------------+
| Field              | Type                         | Null | Key | Default             | Extra          |
+--------------------+------------------------------+------+-----+---------------------+----------------+
| id                 | int(11)                      | NO   | PRI | NULL                | auto_increment |
| client_id          | int(11)                      | NO   | MUL | NULL                |                |
| installation_type  | enum('SINGLE','MULTIPLE')    | YES  |     | NULL                |                |
| street_number      | varchar(50)                  | YES  |     | NULL                |                |
| street_name        | varchar(100)                 | YES  |     | NULL                |                |
| barangay           | varchar(100)                 | YES  |     | NULL                |                |
| city               | varchar(100)                 | YES  |     | NULL                |                |
| machine_number     | varchar(100)                 | YES  |     | NULL                |                |
| department         | varchar(100)                 | YES  |     | NULL                |                |
| reading_date       | int(11)                      | YES  |     | NULL                |                |
| processing_period  | int(11)                      | YES  |     | NULL                |                |
| collection_date    | int(11)                      | YES  |     | NULL                |                |
| zone_id            | int(11)                      | YES  | MUL | NULL                |                |
| status             | enum('ACTIVE','INACTIVE')    | NO   | MUL | ACTIVE              |                |
| created_at         | timestamp                    | YES  |     | current_timestamp() |                |
+--------------------+------------------------------+------+-----+---------------------+----------------+


this is zoning_zone table structure
+--------------+------------------+------+-----+---------------------+----------------+
| Field        | Type             | Null | Key | Default             | Extra          |
+--------------+------------------+------+-----+---------------------+----------------+
| id           | int(11)          | NO   | PRI | NULL                | auto_increment |
| zone_number  | int(11)          | NO   |     | NULL                |                |
| area_center  | varchar(100)     | NO   |     | NULL                |                |
| latitude     | decimal(10,6)    | YES  |     | NULL                |                |
| longitude    | decimal(10,6)    | YES  |     | NULL                |                |
| created_at   | timestamp        | YES  |     | current_timestamp() |                |
+--------------+------------------+------+-----+---------------------+----------------+
