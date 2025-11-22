

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";




CREATE TABLE `token` (
  `id` int(11) NOT NULL,
  `owner_id` bigint(20) NOT NULL,
  `bot_token` varchar(100) NOT NULL,
  `bot_username` varchar(50) NOT NULL,
  `secret_token` varchar(255) DEFAULT NULL COMMENT 'Webhook Secret Token',
  `cost` varchar(50) NOT NULL DEFAULT 'free',
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;



CREATE TABLE `user` (
  `user_id` bigint(20) NOT NULL,
  `username` varchar(255) DEFAULT NULL,
  `mode` varchar(20) NOT NULL DEFAULT 'inline',
  `created_at` datetime NOT NULL,
  `number` int(11) DEFAULT '0',
  `sta` varchar(50) DEFAULT 'none',
  `identity` varchar(10) DEFAULT 'user'
) ENGINE=InnoDB DEFAULT CHARSET=latin1;


ALTER TABLE `token`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `bot_token` (`bot_token`),
  ADD UNIQUE KEY `bot_username` (`bot_username`);

ALTER TABLE `user`
  ADD PRIMARY KEY (`user_id`);




ALTER TABLE `token`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
COMMIT;
