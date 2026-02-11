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

