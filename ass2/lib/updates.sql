-- COMP3311 18s1 Assignment 2
--
-- updates.sql
--
-- Written by Christian Fares (z5116082), May 2018

--  This script takes a "vanilla" MyMyUNSW database and
--  make all of the changes necessary to make the databas
--  work correctly with your PHP scripts.
--  
--  Such changes might involve adding new tables, views,
--  PLpgSQL functions, triggers, etc. Other changes might
--  involve dropping existing tables or redefining existing
--  views and functions
--  
--  Make sure that this script does EVERYTHING necessary to
--  upgrade a vanilla database; if we need to chase you up
--  because you forgot to include some of the changes, and
--  your system will not work correctly because of this, you
--  will receive a 3 mark penalty.
--


create or replace view joinSubjectGroupsAndCodes (code, ao_group)
as
	select s.code, sg.ao_group
	from Subjects s
	inner join Subject_group_members sg on s.id = sg.subject
;

create or replace view joinStreamGroupsAndCodes (code, ao_group)
as
	select s.code, sg.ao_group
	from Streams s
	inner join Stream_group_members sg on s.id = sg.stream
;

create or replace view joinProgramGroupsAndCodes (code, ao_group)
as
	select p.code, sg.ao_group
	from Programs p
	inner join Program_group_members sg on p.id = sg.program
;

-- uses facultyof to find id of faculty the orgunit passed in belongs to
-- then returns its unswid from the table orgunits
create or replace function getFacultyUNSWID (integer) returns text
as $$
	select o.unswid
	from orgunits o
	where o.id = facultyof($1); 
$$ language sql
;

-- given rule id will return the object group associated,
create or replace function getGroupFromRule (integer)
	returns table (id integer, gtype text, gdefby text, definition text)
as $$
	select a.id, a.gtype, a.gdefby, a.definition
	from Rules r
	inner join acad_object_groups a on a.id = r.ao_group
	where r.id = $1;
$$ language sql
;

create or replace function getSemCode(integer)
	returns text
as $$
	select substr(year::text,3,2)||lower(term) 
	from semesters where id = $1;
$$ language sql
;

create or replace function getSemid(integer, text)
	returns integer
as $$
	select s.id
	from semesters s where s.year = $1 and s.term = $2;
$$ language sql
;