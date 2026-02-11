TABLE: barangay_coordinates
+--------------+---------------+------+-----+---------------------+----------------+
| Field        | Type          | Null | Key | Default             | Extra          |
+--------------+---------------+------+-----+---------------------+----------------+
| id           | int(11)       | NO   | PRI | NULL                | auto_increment |
| barangay     | varchar(100)  | YES  | MUL | NULL                |                |
| city         | varchar(100)  | YES  |     | NULL                |                |
| latitude     | decimal(10,6) | YES  |     | NULL                |                |
| longitude    | decimal(10,6) | YES  |     | NULL                |                |
| zone_id      | int(11)       | YES  |     | NULL                |                |
| last_updated | timestamp     | YES  |     | current_timestamp() |                |
+--------------+---------------+------+-----+---------------------+----------------+

TABLE: clients
+--------------------+--------------------------------------+------+-----+---------------------+------------------------------+
| Field              | Type                                 | Null | Key | Default             | Extra                        |
+--------------------+--------------------------------------+------+-----+---------------------+------------------------------+
| id                 | int(11)                              | NO   | PRI | NULL                | auto_increment               |
| classification     | enum('GOVERNMENT','PRIVATE')         | NO   | MUL | NULL                |                              |
| company_name       | varchar(100)                         | NO   | MUL | NULL                |                              |
| main_signatory     | varchar(50)                          | NO   |     | NULL                |                              |
| signatory_position | varchar(50)                          | YES  |     | NULL                |                              |
| main_number        | varchar(20)                          | NO   |     | NULL                |                              |
| main_address       | text                                 | NO   |     | NULL                |                              |
| tin_number         | varchar(20)                          | YES  |     | NULL                |                              |
| email              | varchar(100)                         | YES  |     | NULL                |                              |
| status             | enum('ACTIVE','INACTIVE','SUSPENDED')| YES  | MUL | 'ACTIVE'            |                              |
| created_at         | datetime                             | YES  |     | current_timestamp() |                              |
| updated_at         | datetime                             | YES  |     | current_timestamp() | on update current_timestamp()|
| created_by         | int(11)                              | YES  |     | NULL                |                              |
+--------------------+--------------------------------------+------+-----+---------------------+------------------------------+


TABLE: contracts
+------------------------+-------------------------------------+------+-----+---------------------+------------------------------+
| Field                  | Type                                | Null | Key | Default             | Extra                        |
+------------------------+-------------------------------------+------+-----+---------------------+------------------------------+
| id                     | int(11)                             | NO   | PRI | NULL                | auto_increment               |
| contract_number        | varchar(50)                         | NO   | UNI | NULL                |                              |
| client_id              | int(11)                             | NO   | MUL | NULL                |                              |
| client_name            | varchar(100)                        | NO   |     | NULL                |                              |
| client_classification  | enum('GOVERNMENT','PRIVATE')        | NO   |     | NULL                |                              |
| contract_type          | enum('UMBRELLA','SINGLE CONTRACT')  | NO   |     | NULL                |                              |
| mono_rate              | decimal(10,2)                       | NO   |     | NULL                |                              |
| color_rate             | decimal(10,2)                       | YES  |     | NULL                |                              |
| excess_mono_rate       | decimal(10,2)                       | NO   |     | NULL                |                              |
| excess_color_rate      | decimal(10,2)                       | YES  |     | NULL                |                              |
| min_copies_mono        | int(11)                             | NO   |     | 0                   |                              |
| min_copies_color       | int(11)                             | YES  |     | NULL                |                              |
| spoilage               | decimal(5,2)                        | NO   |     | NULL                |                              |
| vatable                | enum('YES','NO')                    | NO   |     | NULL                |                              |
| contract_file          | varchar(255)                        | YES  |     | NULL                |                              |
| zoning_area            | varchar(100)                        | YES  |     | NULL                |                              |
| reading_date_schedule  | varchar(50)                         | YES  |     | NULL                |                              |
| collection_date        | varchar(50)                         | YES  |     | NULL                |                              |
| barangay               | varchar(100)                        | YES  |     | NULL                |                              |
| city                   | varchar(100)                        | YES  |     | NULL                |                              |
| status                 | enum('ACTIVE','INACTIVE')           | YES  | MUL | 'ACTIVE'            |                              |
| date_created           | datetime                            | YES  | MUL | current_timestamp() |                              |
| created_by             | int(11)                             | YES  |     | NULL                |                              |
| updated_at             | datetime                            | YES  |     | current_timestamp() | on update current_timestamp()|
+------------------------+-------------------------------------+------+-----+---------------------+------------------------------+


TABLE: contract_machines
+---------------+---------------------------------------+------+-----+---------------------+----------------+
| Field         | Type                                  | Null | Key | Default             | Extra          |
+---------------+---------------------------------------+------+-----+---------------------+----------------+
| id            | int(11)                               | NO   | PRI | NULL                | auto_increment |
| contract_id   | int(11)                               | NO   | MUL | NULL                |                |
| machine_type  | varchar(100)                          | NO   |     | NULL                |                |
| brand         | varchar(100)                          | YES  |     | NULL                |                |
| model         | varchar(100)                          | YES  |     | NULL                |                |
| serial_number | varchar(100)                          | NO   | UNI | NULL                |                |
| meter_start   | int(11)                               | NO   |     | 0                   |                |
| machine_number| varchar(50)                           | YES  |     | NULL                |                |
| status        | enum('ACTIVE','INACTIVE','MAINTENANCE')| YES | MUL | 'ACTIVE'            |                |
| date_added    | datetime                              | YES  |     | current_timestamp() |                |
+---------------+---------------------------------------+------+-----+---------------------+----------------+


TABLE: zone_reading_schedule
+---------------+-----------+------+-----+---------------------+----------------+
| Field         | Type      | Null | Key | Default             | Extra          |
+---------------+-----------+------+-----+---------------------+----------------+
| id            | int(11)   | NO   | PRI | NULL                | auto_increment |
| zone_id       | int(11)   | NO   | MUL | NULL                |                |
| reading_date  | int(11)   | NO   |     | NULL                |                |
| machine_count | int(11)   | YES  |     | 0                   |                |
| max_capacity  | int(11)   | YES  |     | 20                  |                |
| created_at    | timestamp | YES  |     | current_timestamp() |                |
+---------------+-----------+------+-----+---------------------+----------------+


TABLE: zoning_clients
+---------------+--------------------------------+------+-----+---------------------+----------------+
| Field         | Type                           | Null | Key | Default             | Extra          |
+---------------+--------------------------------+------+-----+---------------------+----------------+
| id            | int(11)                        | NO   | PRI | NULL                | auto_increment |
| company_name  | varchar(200)                   | NO   |     | NULL                |                |
| classification| enum('GOVERNMENT','PRIVATE')   | NO   |     | NULL                |                |
| contact_number| varchar(50)                    | NO   |     | NULL                |                |
| email         | varchar(100)                   | NO   | UNI | NULL                |                |
| created_at    | timestamp                      | YES  |     | current_timestamp() |                |
+---------------+--------------------------------+------+-----+---------------------+----------------+


TABLE: zoning_machine
+-------------------+--------------------------------+------+-----+---------------------+----------------+
| Field             | Type                           | Null | Key | Default             | Extra          |
+-------------------+--------------------------------+------+-----+---------------------+----------------+
| id                | int(11)                        | NO   | PRI | NULL                | auto_increment |
| client_id         | int(11)                        | NO   | MUL | NULL                |                |
| installation_type | enum('SINGLE','MULTIPLE')      | YES  |     | NULL                |                |
| street_number     | varchar(50)                    | YES  |     | NULL                |                |
| street_name       | varchar(100)                   | YES  |     | NULL                |                |
| barangay          | varchar(100)                   | YES  |     | NULL                |                |
| city              | varchar(100)                   | YES  |     | NULL                |                |
| machine_number    | varchar(100)                   | YES  |     | NULL                |                |
| department        | varchar(100)                   | YES  |     | NULL                |                |
| reading_date      | int(11)                        | YES  |     | NULL                |                |
| processing_period | int(11)                        | YES  |     | NULL                |                |
| collection_date   | int(11)                        | YES  |     | NULL                |                |
| zone_id           | int(11)                        | YES  | MUL | NULL                |                |
| created_at        | timestamp                      | YES  |     | current_timestamp() |                |
+-------------------+--------------------------------+------+-----+---------------------+----------------+


TABLE: zoning_zone
+--------------+---------------+------+-----+---------------------+----------------+
| Field        | Type          | Null | Key | Default             | Extra          |
+--------------+---------------+------+-----+---------------------+----------------+
| id           | int(11)       | NO   | PRI | NULL                | auto_increment |
| zone_number  | int(11)       | NO   |     | NULL                |                |
| area_center  | varchar(100)  | NO   |     | NULL                |                |
| reading_date | int(11)       | YES  |     | NULL                |                |
| latitude     | decimal(10,6) | YES  |     | NULL                |                |
| longitude    | decimal(10,6) | YES  |     | NULL                |                |
| created_at   | timestamp     | YES  |     | current_timestamp() |                |
+--------------+---------------+------+-----+---------------------+----------------+

