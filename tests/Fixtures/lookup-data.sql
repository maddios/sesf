-- Reference/lookup rows from sesf_dev (no personal data), generated 2026-10-24. Loaded into the ephemeral test DB after the schema.
SET FOREIGN_KEY_CHECKS=0;

INSERT INTO `user_type` (`id`,`name`) VALUES
('1','Regular'),
('2','Judge'),
('3','Administrator');

INSERT INTO `user_guest_type` (`id`,`name`) VALUES
('1','Adult'),
('2','Child (Under 13)');

INSERT INTO `sponsor_type` (`id`,`name`) VALUES
('1','M'),
('2','Gold'),
('3','Silber'),
('4','Bronzit');

INSERT INTO `show_agenda_type` (`id`,`name`) VALUES
('1','Agenda'),
('2','Judging');

INSERT INTO `show_extra_type` (`id`,`name`) VALUES
('1','Cars'),
('2','Guests'),
('3','Registration'),
('4','Numeric List');

INSERT INTO `gallery_item_type` (`id`,`name`) VALUES
('1','Image');

INSERT INTO `gallery_type` (`id`,`name`) VALUES
('1','not sure');

INSERT INTO `judging_type` (`id`,`name`) VALUES
('1','Clean'),
('2','Super Clean'),
('3','Concours');

INSERT INTO `payment_method` (`id`,`name`) VALUES
('1','PayPal'),
('2','Check');

INSERT INTO `car_type` (`id`,`name`) VALUES
('1','Car'),
('2','Motorcycle');

INSERT INTO `car_body` (`id`,`name`) VALUES
('1','e24'),
('2','e63 (Coupe)'),
('3','e64 (Conv)'),
('4','e31 (8 Series)'),
('5','e9 (CS)'),
('6','Other');

INSERT INTO `shirt_type` (`id`,`name`,`price`) VALUES
('1','Mens S T-Shirt','35'),
('2','Mens M T-Shirt','35'),
('3','Mens L T-Shirt','35'),
('4','Mens XL T-Shirt','35'),
('5','Mens XXL T-Shirt','35'),
('6','Mens XXXL T-Shirt','35'),
('7','Womens S T-Shirt','35'),
('8','Womens M T-Shirt','35'),
('9','Womens L T-Shirt','35'),
('10','Womens XL T-Shirt','35'),
('11','Womens XXL T-Shirt','35'),
('12','Mens S Polo shirt','55'),
('13','Mens M Polo shirt','55'),
('14','Mens L Polo shirt','55'),
('15','Mens XL Polo shirt','55'),
('16','Mens XXL Polo shirt','55'),
('17','Mens XXXL Polo shirt','55'),
('18','Womens S Polo shirt','55'),
('19','Womens M Polo shirt','55'),
('20','Womens L Polo shirt','55'),
('21','Womens XL Polo shirt','55'),
('22','Womens XXL Polo shirt','55');

INSERT INTO `judging_form` (`id`,`name`,`type_id`,`created_at`,`updated_at`) VALUES
('1','Concours','3','2024-04-21 17:30:53',NULL),
('2','Super Clean','2','2024-04-21 17:30:53',NULL),
('3','Clean','1','2024-04-21 17:30:53',NULL);

INSERT INTO `judging_form_question` (`id`,`form_id`,`type`,`title`,`description`,`page`,`page_name`,`sort_order`,`valueMin`,`valueMax`,`valueStep`,`has_quarter`,`created_at`,`updated_at`) VALUES
('1','1','rating','Exterior body panels, Paint, fit and finish',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('2','1','rating','All window glass (inside and out), mirrors, exterior light covers',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('3','1','rating','Exterior trim, moldings, locks, tracks, emblems, antenna, Wipers and blades, license plates, grills.',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('4','1','rating','Wheels and tires.',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('5','1','rating','Bumpers (inside and out), exhaust tips, fuel door (inside and out), sunroof track (as applicable).',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('6','1','rating','Upholstery and headliner, visors, door panels.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('7','1','rating','Seat belts, mirrors, accessory lights and rear shelf.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('8','1','rating','Door jambs, hinges, gaskets, weather-stripping.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('9','1','rating','Carpets, foot well, seat rails and pedals.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('10','1','rating','Dash board, glove box, shift lever, ash trays, vents, cup holders, steering wheel, steering column and associated controls.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('11','1','rating','Lid underside, latches, hinges, carpet, and side walls, painted surfaces.',NULL,'3','Trunk','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('12','1','rating','Spare tire and well and related pieces, weather stripping, trim.',NULL,'3','Trunk','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('13','1','rating','Tools, jack & accessories, battery cables, covers.',NULL,'3','Trunk','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('14','1','rating','Basic Engine - block, head(s), timing covers, bell-housings, mounts, supporting frame element, intake, exhaust, all covers.',NULL,'4','Engine','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('15','1','rating','Engine Accessories - all other engine-mounted components, parts, wiring, hoses, filters, reservoirs, attached fans, belts, cables, etc.',NULL,'4','Engine','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('16','1','rating','Firewall, Wheel Housings, Sub-Frame - including cables, ducts, wiper motor, wall, brake and clutch reservoir, A/C lines and other components mounted to body. All other accessories / components within the compartment and not included in other categories (i.e. fuse box, cruise control, etc.',NULL,'4','Engine','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('17','1','rating','Hood (underside) and Compartment front Wall - underside - latches, hinges, struts, soundproofing, weather stripping, interior surfaces, radiator, shrouds, oil cooler, mountings, condenser, auxiliary fans, horns, headlight housings, etc.',NULL,'4','Engine','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('18','2','rating','Exterior body panels, Paint, fit and finish',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('19','2','rating','All window glass (inside and out), mirrors, exterior light covers',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('20','2','rating','Exterior trim, moldings, locks, tracks, emblems, antenna, Wipers and blades, license plates, grills.',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('21','2','rating','Wheels and tires.',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('22','2','rating','Bumpers (inside and out), exhaust tips, fuel door (inside and out), sunroof track (as applicable).',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('23','2','rating','Upholstery and headliner, visors, door panels.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('24','2','rating','Seat belts, mirrors, accessory lights and rear shelf.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('25','2','rating','Door jambs, hinges, gaskets, weather-stripping.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('26','2','rating','Carpets, foot well, seat rails and pedals.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('27','2','rating','Dash board, glove box, shift lever, ash trays, vents, cup holders, steering wheel, steering column and associated controls.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('28','2','rating','Lid underside, latches, hinges, carpet, and side walls, painted surfaces.',NULL,'3','Trunk','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('29','2','rating','Spare tire and well and related pieces, weather stripping, trim.',NULL,'3','Trunk','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('30','2','rating','Tools, jack & accessories, battery cables, covers.',NULL,'3','Trunk','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('31','2','rating','Basic Engine - block, head(s), timing covers, bell-housings, mounts, supporting frame element, intake, exhaust, all covers.',NULL,'4','Engine','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('32','2','rating','Engine Accessories - all other engine-mounted components, parts, wiring, hoses, filters, reservoirs, attached fans, belts, cables, etc.',NULL,'4','Engine','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('33','2','rating','Firewall, Wheel Housings, Sub-Frame - including cables, ducts, wiper motor, wall, brake and clutch reservoir, A/C lines and other components mounted to body. All other accessories / components within the compartment and not included in other categories (i.e. fuse box, cruise control, etc.',NULL,'4','Engine','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('34','2','rating','Hood (underside) and Compartment front Wall - underside - latches, hinges, struts, soundproofing, weather stripping, interior surfaces, radiator, shrouds, oil cooler, mountings, condenser, auxiliary fans, horns, headlight housings, etc.',NULL,'4','Engine','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('35','3','rating','Exterior body panels, Paint, fit and finish',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('36','3','rating','All window glass (inside and out), mirrors, exterior light covers',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('37','3','rating','Exterior trim, moldings, locks, tracks, emblems, antenna, Wipers and blades, license plates, grills.',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('38','3','rating','Wheels and tires.',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('39','3','rating','Bumpers (inside and out), exhaust tips, fuel door (inside and out), sunroof track (as applicable).',NULL,'1','Exterior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('40','3','rating','Upholstery and headliner, visors, door panels.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('41','3','rating','Seat belts, mirrors, accessory lights and rear shelf.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('42','3','rating','Door jambs, hinges, gaskets, weather-stripping.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('43','3','rating','Carpets, foot well, seat rails and pedals.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL),
('44','3','rating','Dash board, glove box, shift lever, ash trays, vents, cup holders, steering wheel, steering column and associated controls.',NULL,'2','Interior','1','1','10','1','1','2024-04-21 17:30:53',NULL);

SET FOREIGN_KEY_CHECKS=1;
