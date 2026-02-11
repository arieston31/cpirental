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

zoning_zone table contains the following data:
id | zone_number | area_center                                   | reading_date | latitude  | longitude  | created_at
---|------------|-----------------------------------------------|-------------|----------|-----------|---------------------
1  | 1          | Valenzuela City (Valenzuela Center)          | 3           | 14.701100 | 120.983000 | 2026-01-28 23:22:00
2  | 2          | Caloocan City (Central Caloocan)             | 4           | 14.756000 | 120.981000 | 2026-01-28 23:22:00
3  | 3          | Quezon City North (Novaliches Area)          | 5           | 14.710000 | 121.028000 | 2026-01-28 23:22:00
4  | 4          | Quezon City Central (Diliman)                | 6           | 14.648300 | 121.049900 | 2026-01-28 23:22:00
5  | 5          | Quezon City South / East (Cubao)             | 7           | 14.621900 | 121.053400 | 2026-01-28 23:22:00
6  | 6          | Mandaluyong / San Juan Area                  | 8           | 14.583200 | 121.040900 | 2026-01-28 23:22:00
7  | 7          | Pasig City (Ortigas Center)                  | 9           | 14.582900 | 121.061400 | 2026-01-28 23:22:00
8  | 8          | Makati City (Ayala Center)                   | 10          | 14.554000 | 121.024000 | 2026-01-28 23:22:00
9  | 9          | Manila City (Rizal Park / City Hall)         | 11          | 14.582900 | 120.979700 | 2026-01-28 23:22:00
10 | 10         | Pasay City (Mall of Asia / Bay City)         | 12          | 14.538000 | 121.000000 | 2026-01-28 23:22:00
11 | 11         | Taguig City (Bonifacio Global City)          | 13          | 14.520400 | 121.053900 | 2026-01-28 23:22:00
12 | 12         | Parañaque / Las Piñas Area                   | 14          | 14.466700 | 121.016700 | 2026-01-28 23:22:00
