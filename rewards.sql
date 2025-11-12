CREATE TABLE `rewards` (
  `id` int(11) NOT NULL,
  `wallet_id` int(11) NOT NULL,
  `reward_amount` decimal(18,8) NOT NULL,
  `balance_amount` decimal(18,8) NOT NULL,
  `created_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `rewards`
  ADD PRIMARY KEY (`id`),
  ADD KEY `wallet_id` (`wallet_id`);

ALTER TABLE `rewards`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=1;

ALTER TABLE `rewards`
  ADD CONSTRAINT `rewards_ibfk_1` FOREIGN KEY (`wallet_id`) REFERENCES `wallets` (`id`) ON DELETE CASCADE;
COMMIT;