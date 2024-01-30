-- 
-- Modifications for table `durchfuehrung`
-- 

ALTER TABLE durchfuehrung
ADD COLUMN anbieterurl VARCHAR(200) DEFAULT NULL;

--
-- Table structure for new table `escocategories`
--

CREATE TABLE `escocategories` (
  `id` int(11) NOT NULL,
  `sync_src` int(11) NOT NULL DEFAULT '0',
  `user_created` int(11) NOT NULL DEFAULT '0',
  `user_modified` int(11) NOT NULL DEFAULT '0',
  `user_grp` int(11) NOT NULL DEFAULT '0',
  `user_access` int(11) NOT NULL DEFAULT '0',
  `date_created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `date_modified` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `kategorie` varchar(200) NOT NULL DEFAULT '',
  `url` varchar(200) NOT NULL DEFAULT ''
) ENGINE=MyISAM DEFAULT CHARSET=latin1;

--
-- Triggers `escocategories`
--
DELIMITER $$
CREATE TRIGGER `escocategories_bi_v9_100_0` BEFORE INSERT ON `escocategories` FOR EACH ROW BEGIN
									SET auto_increment_increment = 100;
									SET auto_increment_offset = 0;
								  END
$$
DELIMITER ;

--
-- Indexes for table `escocategories`
--
ALTER TABLE `escocategories`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_modified` (`user_modified`),
  ADD KEY `date_modified` (`date_modified`),
  ADD KEY `stichwort` (`kategorie`),
  ADD KEY `stichwort_sorted` (`url`);

--
-- AUTO_INCREMENT for table `escocategories`
--
ALTER TABLE `escocategories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

-- --------------------------------------------------------

--
-- Table structure for new table `escohierarchy`
--

CREATE TABLE `escohierarchy` (
  `primary_id` int(11) NOT NULL DEFAULT '0',
  `attr_id` int(11) NOT NULL DEFAULT '0',
  `level` int(11) NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

--
-- Indexes for table `escohierarchy`
--
ALTER TABLE `escohierarchy`
  ADD KEY `kurse_stichwort_i0` (`primary_id`),
  ADD KEY `kurse_stichwort_i1` (`attr_id`);


-- --------------------------------------------------------

--
-- Table structure for new table `escoskills`
--

CREATE TABLE `escoskills` (
  `id` int(11) NOT NULL,
  `user_created` int(11) NOT NULL DEFAULT '0',
  `user_grp` int(11) NOT NULL DEFAULT '0',
  `user_access` int(11) NOT NULL DEFAULT '0',
  `date_created` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `date_modified` datetime DEFAULT NULL,
  `kategorie` varchar(500) COLLATE latin1_german1_ci NOT NULL,
  `url` varchar(500) COLLATE latin1_german1_ci NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_german1_ci;

--
-- Indexes for table `escoskills`
--
ALTER TABLE `escoskills`
  ADD PRIMARY KEY (`id`);

-- 
-- Modifications for table `kurse`
-- 
ALTER TABLE kurse
ADD COLUMN lernziele LONGTEXT DEFAULT NULL,

--
-- Table structure for new table `kurse_embedding`
--

CREATE TABLE `kurse_embedding` (
  `kurs_id` int(11) NOT NULL,
  `embedding` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `date_modified` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_german1_ci;

--
-- Indexes for table `kurse_embedding`
--
ALTER TABLE `kurse_embedding`
  ADD PRIMARY KEY (`kurs_id`)

--
-- Table structure for new table `scout_stichwoerter`
--

CREATE TABLE `scout_stichwoerter` (
  `stichwort_id` int(11) NOT NULL,
  `embedding` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
  `search_count` INT NOT NULL DEFAULT 0
  `date_modified` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_german1_ci;

--
-- Indexes for table `scout_stichwoerter`
--
ALTER TABLE `scout_stichwoerter`
  ADD PRIMARY KEY (`stichwort_id`);

-- --------------------------------------------------------
--
-- Table structure for new table `kurse_kompetenz`
--

CREATE TABLE `kurse_kompetenz` (
  `primary_id` int(11) NOT NULL DEFAULT '0',
  `attr_id` int(11) NOT NULL DEFAULT '0',
  `attr_url` text COLLATE latin1_general_ci,
  `suggestion` int(1) NOT NULL DEFAULT '0',
  `preselected` int(1) NOT NULL DEFAULT '0',
  `structure_pos` int(11) NOT NULL DEFAULT '0'
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

--
-- Indexes for table `kurse_kompetenz`
--
ALTER TABLE `kurse_kompetenz`
  ADD KEY `kurse_stichwort_i0` (`primary_id`),
  ADD KEY `kurse_stichwort_i1` (`attr_id`);

-- --------------------------------------------------------
--
-- Table structure for table `thema_esco`
--

CREATE TABLE `thema_esco` (
  `themaid` int(11) NOT NULL,
  `concepturi` varchar(200) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COMMENT='Mapping von WISY-Themen zu ESCO-Konzepten';

--
-- Indexes for table `thema_esco`
--
ALTER TABLE `thema_esco`
  ADD UNIQUE KEY `themaid` (`themaid`,`concepturi`);

-- --------------------------------------------------------

--
-- Table structure for table `x_scout_tags_freq`
--

CREATE TABLE `x_scout_tags_freq` (
  `tag_id` int(11) NOT NULL,
  `portal_id` int(11) NOT NULL,
  `tag_freq` int(11) NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_german1_ci;

--
-- Indexes for table `x_scout_tags_freq`
--
ALTER TABLE `x_scout_tags_freq`
  ADD PRIMARY KEY (`tag_id`);

-- --------------------------------------------------------

CREATE TABLE `kompetenz_blacklist` (
  `id` int(11) NOT NULL,
  `url` varchar(255) COLLATE latin1_general_ci NOT NULL,
  `title` varchar(255) COLLATE latin1_general_ci NOT NULL
) ENGINE=MyISAM DEFAULT CHARSET=latin1 COLLATE=latin1_general_ci;

--
-- Daten für Tabelle `kompetenz_blacklist`
--

INSERT INTO `kompetenz_blacklist` (`id`, `url`, `title`) VALUES
(1, 'http://data.europa.eu/esco/skill/a50c6a0a-5171-4be6-aa2b-50fe4d025d45', '3D-CAD-Schuhprototypen entwerfen'),
(2, 'http://data.europa.eu/esco/skill/f22dd07b-cb0f-4b18-ba54-d922dfaa8c14', 'CAD-Technik für Leisten verwenden'),
(3, 'http://data.europa.eu/esco/skill/061f1c12-9811-46f7-ab3e-1d28367d6c73', 'CAD-Technik für Sohlen verwenden'),
(4, 'http://data.europa.eu/esco/skill/2b1f8e85-6693-47c0-a352-799ebfdfc8e4', 'Leisten für Schuhwerk erstellen'),
(5, 'http://data.europa.eu/esco/skill/f22dd07b-cb0f-4b18-ba54-d922dfaa8c14', 'CAD-Technik für Leisten verwenden');

--
-- Indizes der exportierten Tabellen
--

--
-- Indizes für die Tabelle `kompetenz_blacklist`
--
ALTER TABLE `kompetenz_blacklist`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT für exportierte Tabellen
--

--
-- AUTO_INCREMENT für Tabelle `kompetenz_blacklist`
--
ALTER TABLE `kompetenz_blacklist`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;