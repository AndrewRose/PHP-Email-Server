
CREATE DATABASE /*!32312 IF NOT EXISTS*/ `pes` /*!40100 DEFAULT CHARACTER SET utf8 */;

USE `pes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `domain` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(253) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=0;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_filter` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userId` int(11) NOT NULL,
  `filter` text,
  PRIMARY KEY (`id`),
  KEY `userId` (`userId`),
  CONSTRAINT `email_filter_ibfk_1` FOREIGN KEY (`userId`) REFERENCES `user` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `email_filter_action_mailbox` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `filterId` int(11) NOT NULL,
  `mailboxId` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `filterId` (`filterId`),
  CONSTRAINT `email_filter_action_mailbox_ibfk_1` FOREIGN KEY (`filterId`) REFERENCES `mailbox` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `mailbox` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userId` int(11) NOT NULL,
  `name` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `userId` (`userId`),
  CONSTRAINT `mailbox_ibfk_1` FOREIGN KEY (`userId`) REFERENCES `user` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=0;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `message_queue` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `mail_from` varchar(255) DEFAULT NULL,
  `rcpt_to` varchar(255) DEFAULT NULL,
  `body` text,
  `envelope` text,
  `bodystructure` text,
  `bodystructure_extended` text,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=0;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `password` char(128) DEFAULT NULL,
  `realName` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=0;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_email_address` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `userId` int(11) NOT NULL,
  `domainId` int(11) NOT NULL,
  `localPart` varchar(64) NOT NULL,
  `defaultMailboxId` int(11) DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `userId` (`userId`,`domainId`,`localPart`),
  KEY `domainId` (`domainId`),
  CONSTRAINT `user_email_address_ibfk_1` FOREIGN KEY (`userId`) REFERENCES `user` (`id`),
  CONSTRAINT `user_email_address_ibfk_2` FOREIGN KEY (`domainId`) REFERENCES `domain` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=0;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `user_messages` (
  `userId` int(11) DEFAULT NULL,
  `mailboxId` int(11) DEFAULT NULL,
  `messageId` int(11) DEFAULT NULL,
  `seen` tinyint(1) DEFAULT '0',
  `answered` tinyint(1) DEFAULT '0',
  `flagged` tinyint(1) DEFAULT '0',
  `deleted` tinyint(1) DEFAULT '0',
  `draft` tinyint(1) DEFAULT '0',
  `recent` tinyint(1) DEFAULT '0',
  `id` int(11) NOT NULL AUTO_INCREMENT,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=0;
/*!40101 SET character_set_client = @saved_cs_client */;
