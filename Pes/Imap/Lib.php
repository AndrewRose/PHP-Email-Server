<?php

namespace Pes\Imap;

class Lib
{
	// No, I'm not a fan of regex.
	// And yes, this method needs attention which I will give it over time.
	// 
	public function parseFetch($fetch)
	{
		$tmp = explode(' ', $fetch, 2);
		$dataItems = ['_RANGE' => $this->parseFetchRange($tmp[0])];

		if($tmp[1][0] == '(') // parenthesized list of data items
		{
			$strLen = strlen($tmp[1]) - 2;
			$str = substr($tmp[1], 1, $strLen+1);

			$dataItem = '';
			for($i=0; $i<=$strLen; $i++)
			{
				if($i>=256)
				{
					return FALSE;
				}

				if($dataItem == 'BODY') // BODY, BODY.PEEK, BODYSTRUCTURE
				{
					if($str[$i] == '.')
					{
						$i += 5;
						$dataItems['BODY'] = ['PEEK' => TRUE, 'STRUCTURE' => FALSE, 'ALL' => FALSE, '_CMD' => 'BODY'];
					}
//TODO check for bodystructure 
					else if($str[$i] == 'S')
					{
						$dataItems['BODY'] = ['PEEK' => FALSE, 'STRUCTURE' => TRUE, 'ALL' => FALSE, '_CMD' => 'BODYSTRUCTURE'];
						$i += 10;
						if($i<=$strLen && $str[$i]!=' ')
						{
//TODO fix this.. had to comment out to get Opera Mail working....
//							return FALSE;
						}
					}
					else
					{
						$dataItems['BODY'] = ['PEEK' => FALSE, 'STRUCTURE' => FALSE, 'ALL' => FALSE, '_CMD' => 'BODY'];
					}

					if($str[$i] == '[' && $str[$i] == ']')
					{
						$dataItems['BODY']['ALL'] = TRUE;
						$dataItems['BODY']['_CMD'] .= '[]';
					}
					else if($str[$i] == '[')
					{
						$dataItems['BODY']['_CMD'] .= '[';

						// HEADER, HEADER.FIELDS, HEADER.FIELDS.NOT, MIME, and TEXT
						$i++;
						$tmp2 = '';
						for(;$str[$i]!=']';$i++)
						{
							$dataItems['BODY']['_CMD'] .= $str[$i];

							if($i>=256)
							{
								return FALSE;
							}

							if($str[$i+1] == ']')
							{
								$tmp2 .= $str[$i];
							}

							if($str[$i] != ' ' && $str[$i+1] != ']')
							{
								$tmp2 .= $str[$i];
							}
							else 
							{
								if(strpos($tmp2, 'HEADER') !== FALSE)
								{
									$header = $tmp2;
									$tmp2 = '';
									if($str[$i+1] == '(')
									{
										$dataItems['BODY'][$header] = '';
										$i+=2;
										$dataItems['BODY']['_CMD'] .= '(';

										for(;$str[$i]!=')';$i++)
										{
											if($i>=256)
											{
												return FALSE;
											}
											$dataItems['BODY'][$header] .= $str[$i];
											$dataItems['BODY']['_CMD'] .= $str[$i];
										}
										$dataItems['BODY']['_CMD'] .= ')';	
										$dataItems['BODY'][$header] = explode(' ', $dataItems['BODY'][$header]);
									}
									else
									{

										$dataItems['BODY'][$header] = TRUE;
									}
								}
								else if(strpos($tmp2, 'MIME') !== FALSE)
								{
									$dataItems['BODY']['MIME'] = $tmp2;
									$tmp2 = '';
								}
								else if(strpos($tmp2, 'TEXT') !== FALSE)
								{
									$dataItems['BODY']['TEXT'] = $tmp2;
									$tmp2 = '';
								}
							}
						}
						$dataItems['BODY']['_CMD'] .= ']';
					}
					$dataItem = '';
				}
				else if(($str[$i] == ' ' || $str[$i] == ')') && $dataItem != '') // ALL, FAST, FULL, ENVELOPE, FLAGS, INTERNALDATE, RFC822, RFC822.HEADER, RFC822.SIZE, RFC822.TEXT, UID
				{
					if(in_array(strtoupper($dataItem), ['ALL', 'FAST', 'FULL', 'ENVELOPE', 'FLAGS', 'INTERNALDATE', 'RFC822', 'RFC822.HEADER', 'RFC822.SIZE', 'RFC822.TEXT', 'UID']))
					{
						if($dataItem == 'ALL' || $dataItem == 'FULL' || $dataItem == 'FAST')
						{
							$dataItems += [
								'FLAGS' => TRUE,
								'INTERNALDATE' => TRUE,
								'RFC822.SIZE' => TRUE
							];

							if($dataItem != 'FAST')
							{
								$dataItems += ['ENVELOPE' => TRUE];
							}
						}
						else
						{
							$dataItems[$dataItem] = TRUE;
						}

						$dataItem = '';
					}
					else
					{
//TODO fix this .. update the above to be an exhaustive list
					//	return FALSE;
					}
				}
				else if($str[$i] != ' ')
				{
					$dataItem .= $str[$i];
				}
			}
		}
		else
		{
			$dataItems[$tmp[1]] = TRUE;
		}

		return $dataItems;
	}

	public function parseAppend($paramString)
	{
		$args = [
			'mailbox' => '',
			'flags' => '',
			'date' => '',
			'literal' => ''
		];

		$param = 0;
		$strlen = strlen($paramString);
		for($i=0; $i<$strlen; $i++)
		{
			if($param == 0) // mailbox
			{
				if($paramString[$i] == '"')
				{
					$i++;
					while(($paramString[($i-1)]=='\\' && $paramString[$i]=='"') || $paramString[$i]!='"')
					{
						$args['mailbox'] .= $paramString[$i];
						$i++;
					}
					$i++; // skip the closing DQUOTE
				}
				else
				{
					while($paramString[$i]!=' ')
					{
						$args['mailbox'] .= $paramString[$i];
						$i++;
					}
					$i--;
				}
				$param = 1;
			}
			else if($param == 1) // flags
			{
				if($paramString[$i] == '(')
				{
					$i++;
					while($paramString[$i] != ')' && $paramString != "\n")
					{
						$args['flags'] .= $paramString[$i];
						$i++;
					}
					$i++; // skip closing DQUOTE
				}
				else
				{
					$i--; // counter the outer for loop
				}
				$param = 2;
			}
			else if($param == 2) // date
			{
				if($paramString[$i] == '"')
				{
					$i++;
					while($paramString[$i] != '"' && $paramString != "\n")
					{
						$args['date'] .= $paramString[$i];
						$i++;
					}
					$i++; // skip closing DQUOTE
				}
				else
				{
					$i--; // counter the outer for loop
				}
				$param = 3;
			}
			else if($param == 3)
			{

				if($paramString[$i] == '{')
				{
					$i++;
					while($paramString[$i] != '}' && $paramString != "\n")
					{
						$args['literal'] .= $paramString[$i];
						$i++;
					}
				}
				else
				{
echo "Fault (imap->lib->parseAppend)\n";
exit();
				}
			}
		}
		return $args;
	}

	public function parseFetchRange($range)
	{
		$lastMax = FALSE;
		$ranges = explode(':', $range);

		$fetch = ['ids' =>[], 'ranges' => [], 'openRange' => FALSE];
		foreach($ranges as $range)
		{
			if($range == '*')
			{
				$fetch['openRange'] = $lastMax;
			}
			else
			{
				$range = explode(',', $range);

				foreach($range as $id)
				{
					if(is_numeric($id))
					{
						$fetch['ids'][] = $id;
					}
				}

				$newMin = min($range);
				if($lastMax && is_numeric($newMin))
				{
					$newRange = [$lastMax, $newMin];
					sort($newRange);
					$fetch['ranges'][] = $newRange;
				}

				$lastMax = max($range);
				if(!is_numeric($lastMax))
				{
echo "Fault (imap->lib->parseFetchRange)\n";
exit();
				}
			}
		}

		return $fetch;
	}
}

