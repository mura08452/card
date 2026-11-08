CREATE TABLE `accounts` (
	`id` text PRIMARY KEY NOT NULL,
	`auth_id` text NOT NULL,
	`profile` text NOT NULL,
	`state` text NOT NULL,
	`revision` integer DEFAULT 0 NOT NULL
);
--> statement-breakpoint
CREATE UNIQUE INDEX `accounts_auth_id_unique` ON `accounts` (`auth_id`);--> statement-breakpoint
CREATE TABLE `connections` (
	`id` text PRIMARY KEY NOT NULL,
	`left_id` text NOT NULL,
	`right_id` text NOT NULL,
	`met_at` text NOT NULL,
	`left_name` text NOT NULL,
	`right_name` text NOT NULL,
	FOREIGN KEY (`left_id`) REFERENCES `accounts`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`right_id`) REFERENCES `accounts`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `connection_pair` ON `connections` (`left_id`,`right_id`);--> statement-breakpoint
CREATE TABLE `exchange_requests` (
	`id` text PRIMARY KEY NOT NULL,
	`ticket_hash` text NOT NULL,
	`owner_id` text NOT NULL,
	`requester_id` text NOT NULL,
	`status` text NOT NULL,
	`created` text NOT NULL,
	FOREIGN KEY (`ticket_hash`) REFERENCES `tickets`(`hash`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`owner_id`) REFERENCES `accounts`(`id`) ON UPDATE no action ON DELETE no action,
	FOREIGN KEY (`requester_id`) REFERENCES `accounts`(`id`) ON UPDATE no action ON DELETE no action
);
--> statement-breakpoint
CREATE UNIQUE INDEX `request_ticket_user` ON `exchange_requests` (`ticket_hash`,`requester_id`);--> statement-breakpoint
CREATE TABLE `tickets` (
	`hash` text PRIMARY KEY NOT NULL,
	`owner_id` text NOT NULL,
	`expires` integer NOT NULL,
	FOREIGN KEY (`owner_id`) REFERENCES `accounts`(`id`) ON UPDATE no action ON DELETE no action
);
