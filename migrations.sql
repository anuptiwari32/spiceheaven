alter table products add food_type tinyint default 1; 
alter table branches add capacity int default 20;
alter table branches add menu_image text default '[]';
alter table orders add capacity integer default 0;
alter table products add items varchar(255) null default'[]';

