<?php

namespace Pes\Smtp;

class Handler
{
	public $domainName = FALSE;
	public $maxMailSize = 0;
	public $connections = [];
	public $buffers = [];
	public $maxRead = 256000;
	public $settings = [];
	private $backend = FALSE;

	public function __construct($settings)
	{
		$this->settings = $settings; 
		$this->domainName = $settings['domain'];
		$this->maxMailSize = $settings['maxMailSize'];
		$backendClass = "\\Pes\\Smtp\\Backend\\".$this->settings['backend']['driver'];
		$this->backend = new $backendClass($this->settings['backend'], $this);

		$this->log = new \Pes\Log($settings['log']['file'],$settings['log']['level']);
		$this->log->write('Smtpd started');

		$this->ctx = new \EventSslContext(\EventSslContext::TLS_SERVER_METHOD, [
			\EventSslContext::OPT_LOCAL_CERT  => ($settings['ssl']['root'].'/'.$settings['domain'].'/'.$settings['ssl']['cert']),
			\EventSslContext::OPT_LOCAL_PK    => ($settings['ssl']['root'].'/'.$settings['domain'].'/'.$settings['ssl']['key']),
			//\EventSslContext::OPT_PASSPHRASE  => '',
			\EventSslContext::OPT_VERIFY_PEER => true, // change to true with authentic cert
			\EventSslContext::OPT_ALLOW_SELF_SIGNED => false // change to false with authentic cert
		]);

		$this->base = new \EventBase();
		if(!$this->base) 
		{
			exit("Couldn't open event base\n");
		}

		\Event::signal($this->base, SIGTERM, [$this, 'sigHandler']);
		\Event::signal($this->base, SIGHUP, [$this, 'sigHandler']);

		$socket = stream_socket_server('tcp://0.0.0.0:25', $errno, $errstr);
		stream_set_blocking($socket, 0);
		$this->listener = new \EventListener($this->base, [$this, 'ev_accept'], $this->ctx, \EventListener::OPT_REUSEABLE | \EventListener::OPT_LEAVE_SOCKETS_BLOCKING, -1, $socket);

		if(!$this->listener)
		{
		    exit("Couldn't create listener\n");
		}

		$this->listener->setErrorCallback([$this, 'ev_lerror']);

		$this->base->dispatch();
	}

	public function sigHandler($sig)
	{
		switch($sig)
		{
			case SIGTERM:
			{
			}
			break;
			case SIGHUP:
			{
			}
			break;
			default:
			{
			}
		}
	}

	public function ev_accept($listener, $fd, $address, $ctx)
	{
		if(!$fd)
		{
			$this->log->write('ev_accept fd false?!', 3);
			return;
		}

		static $id = 0;
		$id += 1;

		$this->connections[$id]['totalReadBytes'] = 0;
		$this->connections[$id]['fd'] = $fd;
		$this->connections[$id]['clientData'] = '';
		$this->connections[$id]['dataMode'] = FALSE;
		$this->connections[$id]['message'] = [
			'MAIL FROM' => FALSE,
			'RCPT TO' => [],
			'DATA' => FALSE
		];

		$this->connections[$id]['cnxSsl'] = FALSE;
		$this->connections[$id]['cnx'] = new \EventBufferEvent($this->base, $fd, \EventBufferEvent::OPT_CLOSE_ON_FREE);

		if(!$this->connections[$id]['cnx'])
		{
			$this->log->write('ev_accept('.$id.'): Failed to create EventBufferEvent!',3);
			$this->base->exit(NULL);
			exit(1);
		}

		$this->connections[$id]['cnx']->setCallbacks([$this, 'ev_read'], [$this, 'ev_write'], [$this, 'ev_event'], $id);
		$this->connections[$id]['cnx']->enable(\Event::READ | \Event::WRITE);

		$this->ev_write($id, '220 '.$this->domainName." wazzzap?\r\n");
	}

	function ev_event($id, $event, $events)
	{
		if($events & (\EventBufferEvent::EOF | \EventBufferEvent::ERROR))
		{
			$this->log->write('ev_event(): Freeing Event',3);
// TODO freeing this breaks things.. need to investigate more
//			$id->free();
		}
	}

	function ev_lerror($listener, $ctx)
	{
	}

	public function ev_close($id)
	{
		$this->log->write('ev_close('.$id.')',3);
		$i=0;
		while(($this->connections[$id]['cnx']->getOutput()->length > 0) && ($i < 64))
		{
			$i++;
			$this->connections[$id]['cnx']->getOutput()->write($this->connections[$id]['fd'], $this->maxRead);
		}

		$i=0;
		while(($this->connections[$id]['cnx']->getInput()->length > 0) && ($i < 64))
		{
			$i++;
			$this->connections[$id]['cnx']->getInput()->read($this->connections[$id]['fd'], $this->maxRead);
		}

		if($this->connections[$id]['cnxSsl']) $this->connections[$id]['cnxSsl']->disable(\Event::READ | \Event::WRITE);
		$this->connections[$id]['cnx']->disable(\Event::READ | \Event::WRITE);
		if($this->connections[$id]['cnxSsl']) $this->connections[$id]['cnxSsl']->free();
		$this->connections[$id]['cnx']->free();
		//unset($this->connections[$id]);
	}

	protected function ev_write($id, $string)
	{
		if($this->connections[$id]['cnxSsl'])
		{
			if(!$this->connections[$id]['cnxSsl']->write($string))
			{
				$this->log->write('ev_write('.$id.'): Failed to write to client!',3);
			}
		}
		else
		{
			$this->connections[$id]['cnx']->write($string);
		}
	}

	public function ev_read($buffer, $id)
	{
		if(strlen($this->connections[$id]['clientData']) > $this->maxMailSize)
		{
//TODO need to make sure we don't continue to read and only accept a QUIT
			$this->ev_write($id, "552 Too much data read ".strlen($this->connections[$id]['clientData'])." - ".$this->handler->maxMailSize."\r\n");
		}

		if(!isset($buffer->input) || !$buffer->input->length)
		{
			return;
		}

		while($buffer->input->length > 0)
		{
			$this->connections[$id]['clientData'] .= $buffer->input->read($this->maxRead); //event_buffer_read($buffer, $this->maxRead);
			$clientDataLen = strlen($this->connections[$id]['clientData']);

			if(!$this->connections[$id]['dataMode'] && (strpos($this->connections[$id]['clientData'], "\r\n") !== FALSE))
			{
				while(strpos($this->connections[$id]['clientData'], "\r\n")) // Keep processing commands until we hit DATA and go into dataMode
				{
					list($line, $this->connections[$id]['clientData']) = explode("\r\n", $this->connections[$id]['clientData'], 2);
					$this->cmd($buffer, $id, $line);
					if($this->connections[$id]['dataMode'])
					{
						break 2;
					}
				}
			}
			else if($this->connections[$id]['dataMode'] && ($dataPos = strpos($this->connections[$id]['clientData'], "\r\n.\r\n")))
			{
				list($data, $this->connections[$id]['clientData']) = explode("\r\n.\r\n", $this->connections[$id]['clientData'], 2);

				foreach($this->connections[$id]['message']['RCPT TO'] as $rcpt)
				{
					foreach(mailparse_rfc822_parse_addresses($rcpt) as $address)
					{
						try
						{
							$this->backend->queueMail($this->connections[$id]['message']['MAIL FROM'], $address['address'], $data);
						}
						catch(\Pes\Smtp\Exception $e)
						{
// TODO error reporting needs sorting out! Not always going to be just Too much mail data!
							$this->log->write('ev_read('.$id.'): '.$e->getMessage(),3);
							$this->ev_write($id, $e->getCode()." Too much mail data\r\n");
						}
					}
				}

				//$this->connections[$id]['clientData'] = ''; Keep the buffer as the client could be seding another email!
				$this->connections[$id]['dataMode'] = FALSE;
				$this->connections[$id]['message'] = [
					'MAIL FROM' => FALSE,
					'RCPT TO' => [],
					'DATA' => FALSE
				];

				$this->ev_write($id, "250 2.0.0 OK.\r\n");
			}
		}
	}

	protected function cmd($buffer, $id, $line)
	{
		$this->log->write('cmd('.$id.'):'. $line,3);

		//$line = strtoupper($line);
		switch($line)
		{
			case strncasecmp('EHLO ', $line, 4):
			{
				$this->ev_write($id, "250-STARTTLS\r\n");
				$this->ev_write($id, "250 OK\r\n");
			}
			break;

			case strncasecmp('HELO ', $line, 4):
			{
				$this->ev_write($id, "250-STARTTLS\r\n");
				$this->ev_write($id, "250 OK helo\r\n");
			}
			break;

			case strncasecmp('MAIL FROM: ', $line, 10):
			{
				$this->connections[$id]['message']['MAIL FROM'] = substr($line, 10, strlen($line)-2);
				$this->ev_write($id, "250 2.1.0 OK\r\n");
			}
			break;

			case strncasecmp('RCPT TO: ', $line, 8):
			{
				if(!$this->connections[$id]['message']['MAIL FROM'])
				{
					$this->ev_write($id, "503 5.5.1 MAIL first.\r\n");
				}
				else
				{
// TODO check we are happy with the recipent i.e. not some abuse / spam being relayed.
					$this->connections[$id]['message']['RCPT TO'][] = substr($line, 8, strlen($line)-2);
					$this->ev_write($id, "250 2.1.5 OK\r\n");
				}
			}
			break;

			case strncasecmp('DATA ', $line, 4):
			{
				if(!$this->connections[$id]['message']['MAIL FROM'])
				{
					$this->ev_write($id, "503 5.5.1 MAIL first.\r\n");
				}
				else if(!$this->connections[$id]['message']['RCPT TO'])
				{
					$this->ev_write($id, "503 5.5.1 RCPT first.\r\n");
				}
				else
				{
					$this->connections[$id]['clientData'] = '';
					$this->connections[$id]['dataMode'] = TRUE;
					$this->ev_write($id, "354 Go ahead\r\n");
				}
			}
			break;

			case strncasecmp('QUIT', $line, 3):
			{
				$this->ev_write($id, "250 OK quit\r\n");
				$this->ev_close($id);
			}
			break;


			case strncasecmp('STARTTLS', $line, 3):
			{
				$this->log->write('cmd('.$id.')->starttls', 3);

				// attempts by clients to starttls for a second time result in core dump... took some time to catch this sucker!
				if($this->connections[$id]['cnxSsl'])
				{
					$this->log->write('cmd('.$id.')->starttls failed as already called!', 3);
					$this->ev_write($id, "500 STARTTLS already called!\r\n");
					return;
				}
				$this->ev_write($id, "220 Ready to start TLS\r\n");
				$this->connections[$id]['cnxSsl'] = $this->connections[$id]['cnx']->sslFilter($this->base, $this->connections[$id]['cnx'], $this->ctx, \EventBufferEvent::SSL_ACCEPTING, \EventBufferEvent::OPT_CLOSE_ON_FREE);
				$this->connections[$id]['cnxSsl']->setCallbacks([$this, 'ev_read'], [$this, 'ev_write'], [$this, 'ev_event'], $id);
				$this->connections[$id]['cnxSsl']->enable(\Event::READ | \Event::WRITE);
			}
			break;

			default:
			{
//echo 'unknown command: '.$line."\n";
			}
			break;
		}
	}
}
