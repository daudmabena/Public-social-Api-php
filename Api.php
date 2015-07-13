<?php
ini_set("display_errors", "on");
error_reporting(-1);
class Api {
    public $Curl = array();
    public $output = 'json';
    public $header = false;
    public $graph = 'facebook';
    private $language = 'pt-br';
    public $lang;
    public function __construct() {
        $this->Curl          = (object) $this->Curl;
        $this->Curl->referer = '';
        $this->Curl->agent   = 'Android';
        $this->language      = strtolower($this->language);
        $this->lang          = $this->lang($this->language);
    }
    public function setVar($name, $value) {
        if (preg_match('/([^:]+)(::|->)([a-z0-9_]+)/i', $name, $str)) {
            if (!empty($str[3])) {
                $this->$str[1]->$str[3] = $value;
            }
        } else {
            $this->$name = $value;
        }
    }
    public function setOutput($output, $code) {
        if (gettype($output) == 'string') {
            $output = (object) array(
                'message' => '(#' . $code . ') ' . $output,
                'code' => $code
            );
        } else {
            unset($output->code);
        }
        $this->output = ucfirst($this->output);
        if (method_exists($this, 'return' . $this->output)) {
            $return = 'return' . $this->output;
            return $this->$return($output);
        }
        return $output;
    }
    private function lang($language = false) {
        $lang             = array();
        $lang['pt-br'][0] = 'Adicione o username.';
        $lang['pt-br'][1] = 'A página solicitada não existe ou não esta disponivel.';
        $lang['pt-br'][2] = 'Graph inválido.';
        $lang['pt-br'][3] = 'É necessario o parametro username em sua url para solicitar este recurso';
        if (!empty($lang[$language])) {
            return $lang[$language];
        }
        return $lang;
    }
    public function graph() {
        if (empty($this->username)) {
            $graph      = $this->lang[0];
            $graph_code = 800;
        } elseif (method_exists($this, 'graph_' . $this->graph)) {
            $graph = 'graph_' . $this->graph;
            $graph = $this->$graph();
            if (empty($graph->id) and empty($graph->data->id) and empty($graph->error)) {
                unset($graph);
                $graph        = new stdClass();
                $graph_code   = 803;
                $graph->error = $this->lang[1];
            } else {
                $graph_code = (!empty($graph->meta->code)) ? $graph->meta->code : $graph->code;
            }
            $this->output = ucfirst($this->output);
        } else {
            $graph      = $this->lang[2];
            $graph_code = 404;
        }
        return $this->setOutput($graph, $graph_code);
    }
    private function graph_vk() {
        $this->Curl->agent   = 'Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/43.0.2357.130 Safari/537.36';
        $this->Curl->referer = 'https://vk.com/';
        $urlRequest          = 'https://vk.com/' . $this->username;
        $request             = $this->requestGraph($urlRequest);
        if (empty($request)) {
            return 'false';
        }
        $fields       = $this->setFields();
        $object       = new stdClass();
        $object->code = 0;
        $dom          = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($request->response);
        $dom->preserveWhiteSpace       = false;
        $domPath                       = new DomXpath($dom);
        $request->data                 = new stdClass();
        $request->data->groups_blocked = $domPath->query("//div[@class='groups_blocked']/div[@class='groups_blocked_text']");
        if (!empty($request->data->groups_blocked) and $request->data->groups_blocked->length > 0) {
            $object->error          = new stdClass();
            $object->error->id      = $this->username;
            $object->error->message = trim($request->data->groups_blocked->item(0)->nodeValue);
            return $object;
        }
        if (preg_match('/Groups\.init\((.*?)\);/Ui', $request->response, $request->data->group)) {
            $request->data->group = (!empty($request->data->group[1])) ? json_decode(mb_convert_encoding($request->data->group[1], "UTF-8", "ASCII,JIS,UTF-8,EUC-JP,SJIS")) : false;
            $object->id           = (!empty($request->data->group->group_id)) ? $request->data->group->group_id : 0;
            $object->type         = 'community';
            if (!empty($fields['username'])) {
                $object->username = (!empty($request->data->group->loc)) ? $request->data->group->loc : '';
            }
            if (!empty($fields['name'])) {
                $object->name = (!empty($request->data->group->back)) ? mb_convert_encoding($request->data->group->back, "UTF-8", "ASCII,JIS,UTF-8,EUC-JP,SJIS") : '';
            }
            if (!empty($fields['photo'])) {
                $object->photo        = new stdClass();
                $object->photo->src   = '';
                $request->data->photo = $domPath->query("//div[@id='page_avatar']/img[@src]");
                if (!empty($request->data->photo) and $request->data->photo->length > 0) {
                    $object->photo->src = $request->data->photo->item(0)->getAttribute('src');
                }
            }
            if (!empty($fields['description'])) {
                $object->description        = '';
                $request->data->description = $domPath->query("//meta[@name='description']");
                if (!empty($request->data->description) and $request->data->description->length > 0) {
                    $object->description = $request->data->description->item(0)->getAttribute('content');
                }
            }
            if (!empty($fields['location'])) {
                $object->location          = new stdClass();
                $object->location->name    = '';
                $object->location->city    = '';
                $object->location->country = '';
                $request->data->location   = $domPath->query("//div[@class='group_info']//a[contains(@href,'[country]') or contains(@href,'[city]')]");
                if (!empty($request->data->location) and $request->data->location->length > 0) {
                    $object->location->name    = $request->data->location->item(0)->nodeValue;
                    $request->data->location   = $this->parse_string($request->data->location->item(0)->getAttribute('href'), '?', 'last');
                    $request->data->location   = (!empty($request->data->location['c'])) ? (object) $request->data->location['c'] : false;
                    $object->location->city    = (!empty($request->data->location->city)) ? (int) $request->data->location->city : '';
                    $object->location->country = (!empty($request->data->location->country)) ? (int) $request->data->location->country : '';
                }
            }
            if (!empty($fields['website'])) {
                $object->website        = '';
                $request->data->website = $domPath->query("//div[@class='group_info']//a[contains(@href,'away.php')]");
                if (!empty($request->data->website) and $request->data->website->length > 0) {
                    $request->data->website = $request->data->website->item(0);
                    $object->website        = $request->data->website->nodeValue;
                }
            }
            if (!empty($fields['counts'])) {
                $object->counts           = new stdClass();
                $object->counts->members  = 0;
                $object->counts->topics   = 0;
                $object->counts->albums   = 0;
                $object->counts->photos   = 0;
                $object->counts->audios   = 0;
                $object->counts->scraps   = 0;
                $object->counts->docs     = 0;
                $object->counts->contacts = 0;
                $object->counts->links    = 0;
                $request->data->members   = $domPath->query("//div[@id='group_followers']//div[@class='p_header_bottom']");
                if (!empty($request->data->members) and $request->data->members->length > 0) {
                    $object->counts->members = (int) preg_replace('/([^0-9]+)/i', '', $request->data->members->item(0)->nodeValue);
                }
                $request->data->topics = $domPath->query("//div[@id='group_topics']//div[@class='p_header_bottom']");
                if (!empty($request->data->topics) and $request->data->topics->length > 0) {
                    $object->counts->topics = (int) preg_replace('/([^0-9]+)/i', '', $request->data->topics->item(0)->nodeValue);
                }
                $request->data->photos = $domPath->query("//div[@id='group_photos']//div[@class='p_header_bottom']");
                if (!empty($request->data->photos) and $request->data->photos->length > 0) {
                    $object->counts->photos = (int) preg_replace('/([^0-9]+)/i', '', $request->data->photos->item(0)->nodeValue);
                }
                $request->data->albums = $domPath->query("//div[@id='group_albums']//div[@class='p_header_bottom']");
                if (!empty($request->data->albums) and $request->data->albums->length > 0) {
                    $object->counts->albums = (int) preg_replace('/([^0-9]+)/i', '', $request->data->albums->item(0)->nodeValue);
                } else if ($object->counts->photos >= 1) {
                    $object->counts->albums = 1;
                }
                $request->data->audios = $domPath->query("//div[@id='group_audios']//div[@class='p_header_bottom']");
                if (!empty($request->data->audios) and $request->data->audios->length > 0) {
                    $object->counts->audios = (int) preg_replace('/([^0-9]+)/i', '', $request->data->audios->item(0)->nodeValue);
                }
                $request->data->scraps = $domPath->query("//b[@id='page_wall_posts_count']");
                if (!empty($request->data->scraps) and $request->data->scraps->length > 0) {
                    $object->counts->scraps = (int) preg_replace('/([^0-9]+)/i', '', $request->data->scraps->item(0)->nodeValue);
                }
                $request->data->docs = $domPath->query("//div[@id='group_docs']//div[@class='p_header_bottom']");
                if (!empty($request->data->docs) and $request->data->docs->length > 0) {
                    $object->counts->docs = (int) preg_replace('/([^0-9]+)/i', '', $request->data->docs->item(0)->nodeValue);
                }
                $request->data->contacts = $domPath->query("//div[@id='group_contacts']//div[@class='p_header_bottom']");
                if (!empty($request->data->contacts) and $request->data->contacts->length > 0) {
                    $object->counts->contacts = (int) preg_replace('/([^0-9]+)/i', '', $request->data->contacts->item(0)->nodeValue);
                }
                $request->data->links = $domPath->query("//div[@id='group_links']//div[@class='p_header_bottom']");
                if (!empty($request->data->links) and $request->data->links->length > 0) {
                    $object->counts->links = (int) preg_replace('/([^0-9]+)/i', '', $request->data->links->item(0)->nodeValue);
                }
            }
            if (!empty($fields['group']['only_official'])) {
                $object->only_official = (!empty($request->data->group->only_official)) ? true : false;
            }
        } elseif (preg_match('/public\.init\((.*?)\);/Ui', $request->response, $request->data->page)) {
            $request->data->page = (!empty($request->data->page[1])) ? json_decode(mb_convert_encoding($request->data->page[1], "UTF-8", "ASCII,JIS,UTF-8,EUC-JP,SJIS")) : false;
            $object->id          = (!empty($request->data->page->public_id)) ? $request->data->page->public_id : 0;
            $object->type        = 'page';
            if (!empty($fields['username'])) {
                $object->username = (!empty($request->data->page->public_link)) ? $this->findOccurrence($request->data->page->public_link, '/', 'last') : '';
            }
            if (!empty($fields['name'])) {
                $object->name = (!empty($request->data->page->back)) ? mb_convert_encoding($request->data->page->back, "UTF-8", "ASCII,JIS,UTF-8,EUC-JP,SJIS") : '';
            }
            if (!empty($fields['photo'])) {
                $object->photo             = new stdClass();
                $object->photo->src        = '';
                $request->data->post_photo = $domPath->query("//div[@id='group_narrow']/a[contains(@href,'/photo-') and @onclick]");
                $object->photo->post       = (!empty($request->data->post_photo->length)) ? 'https://vk.com' . $request->data->post_photo->item(0)->getAttribute('href') : '';
                $request->data->photo      = $domPath->query("//div[@id='page_avatar']/img[@src]");
                if (!empty($request->data->photo) and $request->data->photo->length > 0) {
                    $object->photo->src = $request->data->photo->item(0)->getAttribute('src');
                }
            }
            if (!empty($fields['counts'])) {
                $object->counts            = new stdClass();
                $object->counts->posts     = 0;
                $object->counts->followers = (int) preg_replace('/([^0-9]+)/i', '', $request->data->page->otherCount);
                $request->data->posts      = $domPath->query("//b[@id='page_wall_posts_count']");
                if (!empty($request->data->posts) and $request->data->posts->length > 0) {
                    $object->counts->posts = (int) preg_replace('/([^0-9]+)/i', '', $request->data->posts->item(0)->nodeValue);
                }
                $object->counts->links = 0;
                $request->data->links  = $domPath->query("//div[@id='public_links']//div[@class='p_header_bottom']");
                if (!empty($request->data->links) and $request->data->links->length > 0) {
                    $object->counts->links = (int) preg_replace('/([^0-9]+)/i', '', $request->data->links->item(0)->nodeValue);
                }
                $object->counts->albums = 0;
                $request->data->albums  = $domPath->query("//div[@id='public_albums']//div[@class='p_header_bottom']");
                if (!empty($request->data->albums) and $request->data->albums->length > 0) {
                    $object->counts->albums = (int) preg_replace('/([^0-9]+)/i', '', $request->data->albums->item(0)->nodeValue);
                }
                $object->counts->audios = 0;
                $request->data->audios  = $domPath->query("//div[@id='public_audios']//div[@class='p_header_bottom']");
                if (!empty($request->data->audios) and $request->data->audios->length > 0) {
                    $object->counts->audios = (int) preg_replace('/([^0-9]+)/i', '', $request->data->audios->item(0)->nodeValue);
                }
                $object->counts->topics = 0;
                $request->data->topics  = $domPath->query("//div[@id='public_topics']//div[@class='p_header_bottom']");
                if (!empty($request->data->topics) and $request->data->topics->length > 0) {
                    $object->counts->topics = (int) preg_replace('/([^0-9]+)/i', '', $request->data->topics->item(0)->nodeValue);
                }
                $object->counts->contacts = 0;
                $request->data->contacts  = $domPath->query("//div[@id='public_contacts']//div[@class='p_header_bottom']");
                if (!empty($request->data->contacts) and $request->data->contacts->length > 0) {
                    $object->counts->contacts = (int) preg_replace('/([^0-9]+)/i', '', $request->data->contacts->item(0)->nodeValue);
                }
            }
            if (!empty($fields['page']['text'])) {
                $object->text        = '';
                $request->data->text = $domPath->query("//div[@id='page_current_info']/span[@class='current_text']");
                if (!empty($request->data->text) and $request->data->text->length > 0) {
                    $object->text = mb_convert_encoding($request->data->text->item(0)->nodeValue, "UTF-8", "ASCII,JIS,UTF-8,EUC-JP,SJIS");
                }
            }
            if (!empty($fields['page']['verified'])) {
                $request->data->verified = $domPath->query("//div[@class='page_verified' and @onmouseover]");
                $object->verified        = ($request->data->verified->length > 0) ? true : false;
            }
        } elseif (preg_match('/Profile\.init\((.*?)\);/Ui', $request->response, $request->data->User)) {
            $request->data->User = (!empty($request->data->User[1])) ? json_decode(mb_convert_encoding($request->data->User[1], "UTF-8", "ASCII,JIS,UTF-8,EUC-JP,SJIS")) : false;
            $object->id          = (!empty($request->data->User->user_id)) ? $request->data->User->user_id : 0;
            $object->type        = 'profile';
            if (!empty($fields['username'])) {
                $object->username = (!empty($request->data->User->loc)) ? $request->data->User->loc : '';
            }
            if (!empty($fields['name'])) {
                $object->name = (!empty($request->data->User->back)) ? $request->data->User->back : '';
            }
            unset($request->data->User);
            if (!empty($fields['photo'])) {
                $object->photo             = new stdClass();
                $object->photo->src        = '';
                $request->data->post_photo = $domPath->query("//div[@id='page_avatar']/a");
                $object->photo->post       = (!empty($request->data->post_photo->length)) ? 'https://vk.com' . $request->data->post_photo->item(0)->getAttribute('href') : '';
                $request->data->photo      = $domPath->query("//img[@src]", $request->data->post_photo->item(0));
                if (!empty($request->data->photo) and $request->data->photo->length > 0) {
                    $object->photo->src = $request->data->photo->item(0)->getAttribute('src');
                }
            }
            $request->data->Profile = $domPath->query("//div[@id='profile_wide']");
            if (!empty($request->data->Profile) and $request->data->Profile->length > 0) {
                if (!empty($fields['profile']['birthday'])) {
                    $object->birthday        = new stdClass();
                    $object->birthday->day   = '';
                    $object->birthday->month = '';
                    $object->birthday->year  = '';
                    $request->data->Birthday = $domPath->query("//a[contains(@href,'bday') and contains(@href,'bmonth')]", $request->data->Profile->item(0));
                    if (!empty($request->data->Birthday) and $request->data->Birthday->length > 0) {
                        $request->data->month    = $this->parse_string($request->data->Birthday->item(0)->getAttribute('href'), '?', 'last');
                        $request->data->month    = (!empty($request->data->month['c'])) ? (object) $request->data->month['c'] : false;
                        $object->birthday->day   = (!empty($request->data->month->bday)) ? $request->data->month->bday : '';
                        $object->birthday->month = (!empty($request->data->month->bmonth)) ? $request->data->month->bmonth : '';
                        unset($request->data->month);
                        $request->data->Birthday = $domPath->query("//a[contains(@href,'byear')]", $request->data->Profile->item(0));
                        if (!empty($request->data->Birthday) and $request->data->Birthday->length > 0) {
                            $request->data->year    = $this->parse_string($request->data->Birthday->item(0)->getAttribute('href'), '?', 'last');
                            $request->data->year    = (!empty($request->data->year['c'])) ? (object) $request->data->year['c'] : false;
                            $object->birthday->year = (!empty($request->data->year->byear)) ? (int) $request->data->year->byear : '';
                        }
                    }
                }
                if (!empty($fields['profile']['hometown'])) {
                    $object->hometown        = '';
                    $request->data->hometown = $domPath->query("//a[contains(@href,'hometown')]", $request->data->Profile->item(0));
                    if (!empty($request->data->hometown) and $request->data->hometown->length > 0) {
                        $request->data->hometown = $this->parse_string($request->data->hometown->item(0)->getAttribute('href'), '?', 'last');
                        $request->data->hometown = (!empty($request->data->hometown['c']['hometown'])) ? $request->data->hometown['c']['hometown'] : false;
                        $object->hometown        = (!empty($request->data->hometown)) ? $request->data->hometown : '';
                    }
                }
                if (!empty($fields['profile']['current_city'])) {
                    $object->current_city          = new stdClass();
                    $object->current_city->name    = '';
                    $object->current_city->city    = '';
                    $object->current_city->country = '';
                    $request->data->current_city   = $domPath->query("//a[contains(@href,'[city]')]", $request->data->Profile->item(0));
                    if (!empty($request->data->current_city) and $request->data->current_city->length > 0) {
                        $object->current_city->name    = $request->data->current_city->item(0)->nodeValue;
                        $request->data->current_city   = $this->parse_string($request->data->current_city->item(0)->getAttribute('href'), '?', 'last');
                        $request->data->current_city   = (!empty($request->data->current_city['c'])) ? (object) $request->data->current_city['c'] : false;
                        $object->current_city->city    = (!empty($request->data->current_city->city)) ? (int) $request->data->current_city->city : '';
                        $object->current_city->country = (!empty($request->data->current_city->country)) ? (int) $request->data->current_city->country : '';
                    }
                }
                if (!empty($fields['profile']['social'])) {
                    $object->social            = new stdClass();
                    $object->social->skype     = '';
                    $object->social->instagram = '';
                    $object->social->facebook  = '';
                    $request->data->skype      = $domPath->query("//a[contains(@href,'skype:')]", $request->data->Profile->item(0));
                    if (!empty($request->data->skype) and $request->data->skype->length > 0) {
                        $object->social->skype = (!empty($request->data->skype->item(0)->nodeValue)) ? $request->data->skype->item(0)->nodeValue : '';
                    }
                    $request->data->instagram = $domPath->query("//a[contains(@href,'instagram.com')]", $request->data->Profile->item(0));
                    if (!empty($request->data->instagram) and $request->data->instagram->length > 0) {
                        $request->data->instagram            = $request->data->instagram->item(0);
                        $object->social->instagram           = new stdClass();
                        $object->social->instagram->username = $request->data->instagram->nodeValue;
                        $object->social->instagram->url      = 'http://instagram.com/' . $object->instagram->username;
                    }
                    $request->data->facebook = $domPath->query("//a[contains(@href,'facebook.com')]", $request->data->Profile->item(0));
                    if (!empty($request->data->facebook) and $request->data->facebook->length > 0) {
                        $request->social->data->facebook = $request->data->facebook->item(0);
                        $object->social->facebook        = new stdClass();
                        $object->social->facebook->url   = $request->data->facebook->getAttribute('href');
                        if (preg_match('/away\.php\?to\=(.*)/i', $object->facebook->url, $facebook_url)) {
                            $object->social->facebook->url = (!empty($facebook_url[1])) ? urldecode($facebook_url[1]) : '';
                        }
                        $object->social->facebook->name = $request->data->facebook->nodeValue;
                    }
                    if (count((array) $object->social) == 0) {
                        unset($object->social);
                    }
                }
                if (!empty($fields['website'])) {
                    $object->website        = '';
                    $request->data->website = $domPath->query("//div[@id='profile_full_info']/div[@class='profile_info']//a[contains(@href,'away.php')]", $request->data->Profile->item(0));
                    if (!empty($request->data->website) and $request->data->website->length > 0) {
                        $request->data->website = $request->data->website->item(0);
                        $object->website        = $request->data->website->nodeValue;
                    }
                }
                if (!empty($fields['profile']['education'])) {
                    $object->education              = new stdClass();
                    $object->education->school_name = '';
                    $object->education->school      = '';
                    $object->education->city        = '';
                    $object->education->country     = '';
                    $request->data->education       = $domPath->query("//div[@id='profile_full_info']//a[contains(@href,'[school]')]", $request->data->Profile->item(0));
                    if (!empty($request->data->education) and $request->data->education->length > 0) {
                        $request->data->education       = $request->data->education->item(0);
                        $object->education->school_name = trim($this->findOccurrence($request->data->education->nodeValue, ':', 'last'));
                        $request->data->education       = $this->parse_string($request->data->education->getAttribute('href'), '?', 'last');
                        $request->data->education       = (!empty($request->data->education['c'])) ? (object) $request->data->education['c'] : false;
                        $object->education->school      = (!empty($request->data->education->school)) ? (int) $request->data->education->school : '';
                        $object->education->city        = (!empty($request->data->education->school_city)) ? (int) $request->data->education->school_city : '';
                        $object->education->country     = (!empty($request->data->education->school_country)) ? (int) $request->data->education->school_country : '';
                    }
                }
                if (!empty($fields['counts'])) {
                    $object->counts            = new stdClass();
                    $object->counts->photos    = 0;
                    $object->counts->posts     = 0;
                    $object->counts->followers = 0;
                    $object->counts->videos    = 0;
                    $request->data->photos     = $domPath->query("//div[@id='profile_photos_module']/a/div", $request->data->Profile->item(0));
                    if (!empty($request->data->photos) and $request->data->photos->length > 0) {
                        $object->counts->photos = (int) preg_replace('/([^0-9]+)/i', '', $request->data->photos->item(0)->nodeValue);
                    }
                    $request->data->posts = $domPath->query("//b[@id='page_wall_posts_count']", $request->data->Profile->item(0));
                    if (!empty($request->data->posts) and $request->data->posts->length > 0) {
                        $object->counts->posts = (int) preg_replace('/([^0-9]+)/i', '', $request->data->posts->item(0)->nodeValue);
                    }
                    $request->data->followers = $domPath->query("//a[@class='fans']");
                    if (!empty($request->data->followers) and $request->data->followers->length > 0) {
                        $object->counts->followers = (int) preg_replace('/([^0-9]+)/i', '', $request->data->followers->item(0)->nodeValue);
                    }
                    $request->data->videos = $domPath->query("//a[@class='videos']");
                    if (!empty($request->data->videos) and $request->data->videos->length > 0) {
                        $object->counts->videos = (int) preg_replace('/([^0-9]+)/i', '', $request->data->videos->item(0)->nodeValue);
                    }
                }
                if (!empty($fields['profile']['verified'])) {
                    $request->data->verified = $domPath->query("//div[@class='page_verified' and @onmouseover]");
                    $object->verified        = ($request->data->verified->length > 0) ? true : false;
                }
            }
        }
        unset($request, $dom, $domPath);
        return $object;
    }
    private function parse_string($str, $find = false, $place = 'last') {
        if (!empty($find)) {
            parse_str($this->findOccurrence($str, $find, $place), $str);
        } else {
            parse_str($str, $str);
        }
        return $str;
    }
    private function graph_youtube() {
        $this->Curl->agent   = 'Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/43.0.2357.130 Safari/537.36';
        $this->Curl->referer = 'https://www.youtube.com/';
        $urlRequest          = 'https://www.youtube.com/' . ((!empty($_GET['useId'])) ? 'channel/' . $this->username . '/' : $this->username . '/');
        $request             = $this->requestGraph($urlRequest);
        if (empty($request)) {
            return false;
        }
        $fields       = $this->setFields();
        $object       = new stdClass();
        $object->code = 0;
        $dom          = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($request->response);
        $dom->preserveWhiteSpace = false;
        $domPath                 = new DomXpath($dom);
        $id                      = $domPath->query("//meta[@itemprop='channelId']");
        if (!empty($id) and $id->length > 0) {
            $object->id = $id->item(0)->getAttribute('content');
            $url        = $domPath->query("//link[@itemprop='url']");
            if (!empty($url) and $url->length > 0) {
                $object->url = $url->item(0)->getAttribute('href');
            }
            $username = $domPath->query("//img[@class='channel-header-profile-image']");
            if (!empty($username) and $username->length > 0) {
                $object->username = $username->item(0)->getAttribute('title');
            }
            $name = $domPath->query("//meta[@itemprop='name']");
            if (!empty($name) and $name->length > 0) {
                $object->name = $name->item(0)->getAttribute('content');
            }
            if (empty($object->name) and !empty($object->username)) {
                $object->name = $object->username;
            }
            $image = $domPath->query("//link[@rel='image_src']");
            if (!empty($image) and $image->length > 0) {
                $object->image = $image->item(0)->getAttribute('href');
            }
            $subscribers = $domPath->query("//span[contains(@class,'yt-subscription-button-subscriber-count-branded-horizontal')]");
            if (!empty($subscribers) and $subscribers->length > 0) {
                $object->subscribers = preg_replace('/([^0-9]+)/i', '', $subscribers->item(0)->nodeValue);
            }
            $verified         = $domPath->query("//a[@class='qualified-channel-title-badge']/span[contains(@class,'yt-channel-title-icon-verified')]");
            $object->verified = (!empty($verified) and $verified->length > 0) ? true : false;
            $links            = $domPath->query("//div[@id='header-links']/ul/li[@class='channel-links-item']/a");
            if (!empty($links) and $links->length > 0) {
                $object->links = array();
                foreach ($links as $link => $linkDom) {
                    $object->links[$link]        = new stdClass();
                    $object->links[$link]->name  = $linkDom->getAttribute('title');
                    $object->links[$link]->url   = $linkDom->getAttribute('href');
                    $src                         = $domPath->query("//img[@class='about-channel-link-favicon']", $linkDom);
                    $object->links[$link]->image = ($src->length > 0) ? $src->item($link)->getAttribute('src') : '';
                }
            }
            unset($links);
            $regionsAllowed = $domPath->query("//meta[@itemprop='regionsAllowed']");
            if (!empty($regionsAllowed) and $regionsAllowed->length > 0) {
                $object->regionsAllowed = explode(',', $regionsAllowed->item(0)->getAttribute('content'));
                $object->regionsAllowed = (object) $object->regionsAllowed;
            }
        }
        unset($request, $dom, $domPath);
        return $object;
    }
    private function graph_twitter() {
        $this->Curl->agent   = 'Mozilla/5.0 (Windows NT 6.3; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/43.0.2357.130 Safari/537.36';
        $this->Curl->referer = 'https://twitter.com/';
        $request             = $this->requestGraph('https://twitter.com/' . $this->username . '/');
        if (empty($request)) {
            return false;
        }
        $fields       = $this->setFields();
        $object       = new stdClass();
        $object->code = 0;
        $dom          = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($request->response);
        $dom->preserveWhiteSpace = false;
        $domPath                 = new DomXpath($dom);
        $twitter                 = $domPath->query("//input[@id='init-data' and @class='json-data']");
        if (!empty($twitter) and $twitter->length > 0) {
            $twitter    = json_decode(urldecode($twitter->item(0)->getAttribute('value')));
            $twitter    = $twitter->profile_user;
            $object->id = $twitter->id;
            if (!empty($fields['name'])) {
                $object->name = (!empty($twitter->name)) ? $twitter->name : '';
            }
            if (!empty($fields['screen_name'])) {
                $object->screen_name = (!empty($twitter->screen_name)) ? $twitter->screen_name : '';
            }
            if (!empty($fields['location'])) {
                $object->location = (!empty($twitter->location)) ? $twitter->location : '';
            }
            if (!empty($fields['url'])) {
                $object->url = (!empty($twitter->url)) ? $twitter->url : '';
            }
            if (!empty($fields['description'])) {
                $object->description = (!empty($twitter->description)) ? $twitter->description : '';
            }
            if (!empty($fields['protected'])) {
                $object->protected = (!empty($twitter->protected)) ? true : false;
            }
            if (!empty($fields['counts'])) {
                $object->counts             = new stdClass();
                $object->counts->followers  = (!empty($twitter->followers_count)) ? $twitter->followers_count : 0;
                $object->counts->friends    = (!empty($twitter->friends_count)) ? $twitter->friends_count : 0;
                $object->counts->listed     = (!empty($twitter->listed_count)) ? $twitter->listed_count : 0;
                $object->counts->tweets     = (!empty($twitter->statuses_count)) ? $twitter->statuses_count : 0;
                $object->counts->favourites = (!empty($twitter->favourites_count)) ? $twitter->favourites_count : 0;
            }
            if (!empty($fields['created_at']) and !empty($twitter->created_at)) {
                $object->created_at = $twitter->created_at;
            }
            if (!empty($fields['time_zone']) and !empty($twitter->time_zone)) {
                $object->time_zone = $twitter->time_zone;
            }
            if (!empty($fields['verified'])) {
                $object->verified = (!empty($twitter->verified)) ? true : false;
            }
            if (!empty($fields['lang']) and !empty($twitter->lang)) {
                $object->lang = $twitter->lang;
            }
            if (!empty($fields['badges'])) {
                $object->badges                         = new stdClass();
                $object->badges->contributors_enabled   = (!empty($twitter->contributors_enabled)) ? true : false;
                $object->badges->is_translator          = (!empty($twitter->is_translator)) ? true : false;
                $object->badges->is_translation_enabled = (!empty($twitter->is_translation_enabled)) ? true : false;
            }
            if (!empty($fields['profile'])) {
                $object->profile                             = new stdClass();
                $object->profile->background_color           = (!empty($twitter->profile_background_color)) ? $twitter->profile_background_color : '';
                $object->profile->background_image_url       = (!empty($twitter->profile_background_image_url)) ? $twitter->profile_background_image_url : '';
                $object->profile->background_image_url_https = (!empty($twitter->profile_background_image_url_https)) ? $twitter->profile_background_image_url_https : '';
                $object->profile->background_tile            = (!empty($twitter->profile_background_tile)) ? $twitter->profile_background_tile : '';
                $object->profile->image_url                  = (!empty($twitter->profile_image_url)) ? $twitter->profile_image_url : '';
                $object->profile->image_url_https            = (!empty($twitter->profile_image_url_https)) ? $twitter->profile_image_url_https : '';
                $object->profile->banner_url                 = (!empty($twitter->profile_banner_url)) ? $twitter->profile_banner_url : '';
                $object->profile->link_color                 = (!empty($twitter->profile_link_color)) ? $twitter->profile_link_color : '';
                $object->profile->sidebar_border_color       = (!empty($twitter->profile_sidebar_border_color)) ? $twitter->profile_sidebar_border_color : '';
                $object->profile->sidebar_fill_color         = (!empty($twitter->profile_sidebar_fill_color)) ? $twitter->profile_sidebar_fill_color : '';
                $object->profile->text_color                 = (!empty($twitter->profile_text_color)) ? $twitter->profile_text_color : '';
                $object->profile->use_background_image       = (!empty($twitter->profile_use_background_image)) ? $twitter->profile_use_background_image : '';
            }
        }
        unset($request, $twitter);
        return $object;
    }
    private function graph_instagram() {
        $this->Curl->referer = 'https://instagram.com/';
        $request             = $this->requestGraph('https://instagram.com/' . $this->username . '/');
        if (empty($request)) {
            return false;
        }
        $fields       = $this->setFields();
        $object       = new stdClass();
        $object->meta = (object) array(
            'code' => $request->http_code
        );
        $object->data = new stdClass();
        if (preg_match('/\_sharedData[=| ]+\{([^;]+)}\;/iUms', $request->response, $request->json)) {
            $request->json = (!empty($request->json[1])) ? json_decode('{' . $request->json[1] . '}') : false;
            $request->json = (!empty($request->json->entry_data->ProfilePage[0]->user)) ? $request->json->entry_data->ProfilePage[0]->user : false;
            if ( /*username*/ !empty($fields['username'])) {
                $object->data->username = (!empty($request->json->username)) ? $request->json->username : '';
            }
            if ( /*biography*/ !empty($fields['biography'])) {
                $object->data->biography = (!empty($request->json->biography)) ? $request->json->biography : '';
            }
            if ( /*website*/ !empty($fields['website'])) {
                $object->data->website = (!empty($request->json->external_url)) ? $request->json->external_url : '';
            }
            if ( /*picture*/ !empty($fields['picture'])) {
                $object->data->profile_picture = (!empty($request->json->profile_pic_url)) ? $request->json->profile_pic_url : '';
            }
            if ( /*full_name*/ !empty($fields['full_name'])) {
                $object->data->full_name = (!empty($request->json->full_name)) ? $request->json->full_name : '';
            }
            if ( /*is_private*/ !empty($fields['is_private'])) {
                $object->data->is_private = (!empty($request->json->is_private)) ? true : false;
            }
            if ( /*Counts*/ !empty($fields['counts'])) {
                $object->data->counts              = new stdClass();
                $object->data->counts->media       = (!empty($request->json->media->count)) ? $request->json->media->count : 0;
                $object->data->counts->followed_by = (!empty($request->json->followed_by->count)) ? $request->json->followed_by->count : 0;
                $object->data->counts->follows     = (!empty($request->json->follows->count)) ? $request->json->follows->count : 0;
            }
            if ( /*is_verified*/ !empty($fields['is_verified'])) {
                $object->data->is_verified = (!empty($request->json->is_verified)) ? true : false;
            }
            $object->data->id = (!empty($request->json->id)) ? $request->json->id : $object->data->id;
        }
        unset($request);
        return $object;
    }
    private function graph_facebook() {
        $request = $this->requestGraph();
        if (empty($request)) {
            return false;
        }
        $fields       = $this->setFields();
        $object       = new stdClass();
        $object->id   = '';
        $object->type = '';
        $object->code = 0;
        $dom          = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($request->response);
        $dom->preserveWhiteSpace = false;
        $domPath                 = new DomXpath($dom);
        if (!empty($fields['verify'])) {
            $object->verify = ($domPath->query("//span[contains(@class,'_56_f _5dzy _5d-1')]")->length > 0) ? 'true' : 'false';
        }
        if (preg_match('/\"page_uri\":\"([^"]+)\"/i', $request->response, $link)) {
            $link[1] = stripslashes($link[1]);
            $link[1] = $this->findOccurrence($link[1], '?', 'first');
            if (!empty($fields['link'])) {
                $object->link = $this->mb_convert($link[1]);
            }
            if (!empty($fields['username'])) {
                $object->username = $this->findOccurrence($link[1], '/', 'last');
            }
        }
        if (preg_match('/page\/\?id\=([0-9]+)/i', $request->response, $pageId) || preg_match('/<div id="Pages[^_]+_([0-9]+)"/i', $request->response, $pageId)) {
            $object->id   = $pageId[1];
            $object->type = 'page';
            /*
             * Numero de likes da pagina
             */
            if (!empty($fields['page']['likes'])) {
                $object->likes = 0;
                $likes         = $domPath->query("//span[@id='PagesLikesCountDOMID']/span");
                if (!empty($likes) and $likes->length > 0) {
                    $object->likes = preg_replace('/([^0-9]+)/i', '', $likes->item(0)->nodeValue);
                } else {
                    /*
                     * Segunda tentativa de pegar o numero de likes da pagina
                     */
                    $likes = $domPath->query('//div[@id="PagesVertexInfoPageletController_' . $object->id . '"]//div[@class="_1zuq"]//a[@rel="dialog" and @role="button"]');
                    if (!empty($fields['page']['likes']) and $likes->item(0)) {
                        $object->likes = preg_replace('/([^0-9]+)/i', '', $likes->item(0)->nodeValue);
                    }
                    if (!empty($fields['page']['checkins']) and $likes->item(1)) {
                        $object->checkins = preg_replace('/([^0-9]+)/i', '', $likes->item(1)->nodeValue);
                    }
                }
                unset($likes);
            }
            /*
             * Nome da pagina
             */
            if (!empty($fields['name'])) {
                $object->name = '';
                $name         = $domPath->query("//*[@itemprop='name']");
                if (!empty($name) and $name->length > 0) {
                    $object->name = $name->item(0)->nodeValue;
                } elseif (preg_match('/<[h1|span]+ itemprop="name">([^<]+)<\/h1>/i', $request->response, $name) || preg_match('/meta property=\"og:title\" content=\"([^"]+)\"/i', $request->response, $name)) {
                    /*
                     * Segunda tentativa de pegar o nome da pagina
                     */
                    $object->name = $this->mb_convert($name[1]);
                }
            }
            /*
             * Descrição da pagina
             */
            if (!empty($fields['about'])) {
                $object->about = '';
                $description   = $domPath->query('//meta[@property="og:description"]');
                if (!empty($description) and $description->length > 0) {
                    $object->about = $this->mb_convert($description->item(0)->getAttribute('content'));
                } elseif (preg_match('/meta property=\"og:description\" content=\"([^"]+)\"/i', $request->response, $about)) {
                    /*
                     * Segunda tentativa de pegar a descrição da pagina
                     */
                    $object->about = $this->mb_convert($about[1]);
                }
            }
            /*
             * Rating da pagina
             */
            if (!empty($fields['page']['rating'])) {
                $rating = $domPath->query('//meta[@itemprop="ratingCount"]');
                if (!empty($rating) and $rating->length > 0) {
                    $object->rating = $rating->item(0)->getAttribute('content');
                } elseif (preg_match('/<meta content="([0-9.]+)" itemprop="ratingCount"/i', $request->response, $rating)) {
                    /*
                     * Segunda tentativa de pegar o Rating da pagina
                     */
                    $object->rating = ($rating[1]);
                }
            }
            /*
             * e-mail de contato da pagina
             */
            if (!empty($fields['page']['email']) and preg_match('/<a href="mailto:([^"]+)"/i', $request->response, $mail)) {
                $object->email = ($mail[1]);
            }
            /*
             * id & capa da página
             */
            if (!empty($fields['cover'])) {
                #$object->cover = (object) array();
                $cover = $domPath->query("//img[@class='_xlg img']");
                if (!empty($cover) and $cover->length > 0) {
                    $object->cover = (object) array(
                        'id' => $cover->item(0)->getAttribute('data-fbid'),
                        'source' => $cover->item(0)->getAttribute('src')
                    );
                }
            }
            /*
             * website da pagina 
             */
            if (!empty($fields['page']['website'])) {
                $object->website = '';
                if (preg_match('/\"' . $object->id . '\"\,\"http([^"]+)/i', $request->response, $website)) {
                    $object->website = 'http' . stripslashes(html_entity_decode($website[1]));
                }
            }
            /*
             * Ano de fundação da pagina ou empresa.
             */
            if (!empty($fields['page']['founded'])) {
                $founded = $domPath->query("(//div[@id='PageScrubberPagelet_" . $object->id . "']//a[@data-key])[last()]");
                if (!empty($founded) and $founded->length > 0) {
                    $founded = preg_replace('/([^0-9]+)/i', '', $founded->item(0)->getAttribute('data-key'));
                    if ((int) $founded >= 1000) {
                        $object->founded = $founded;
                    }
                }
            }
            /*
             * curtidas da pagina
             */
            if (!empty($fields['likeds'])) {
                $pagesLiked = $domPath->query("//div[@id='PagePagesLikedByPageSecondaryPagelet_" . $object->id . "']//a[@class='_4-lu ellipsis']");
                if (!empty($pagesLiked) and $pagesLiked->length > 0) {
                    $object->likeds        = new stdClass();
                    $object->likeds->pages = array();
                    foreach ($pagesLiked as $links) {
                        $url = $links->getAttribute('href');
                        if (preg_match('/l\.php\?u=([^\n]+)/i', $url, $newUrl)) {
                            $url = $this->findOccurrence(urldecode($newUrl[1]), 'l.php?u=', 'last');
                        }
                        $username                = strtolower($this->findOccurrence($url, '/', 'last'));
                        $object->likeds->pages[] = (object) array(
                            'name' => $links->nodeValue,
                            'username' => $username,
                            'picture' => 'https://graph.facebook.com/' . $username . '/picture?type=large',
                            'url' => $url
                        );
                    }
                    $object->likeds->pages = (object) $object->likeds->pages;
                    $object->likeds->more  = 'https://www.facebook.com/browse/fanned_pages/?id=' . $object->id;
                }
                unset($pagesLiked);
            }
        } elseif (preg_match('/profile\/([0-9]+)/i', $request->response, $profileId)) {
            $object->id   = $profileId[1];
            $object->type = 'profile';
            /*
             * nome do perfil
             */
            if (!empty($fields['name'])) {
                $object->name = '';
                $name         = $domPath->query('//span[@id="fb-timeline-cover-name"]');
                if (!empty($name) and $name->length > 0) {
                    $name           = $name->item(0);
                    $object->name   = ($name->firstChild->textContent);
                    $alternate_name = $domPath->query("//span[@class='alternate_name']", $name);
                    if (!empty($fields['profile']['name']) and !empty($alternate_name) and $alternate_name->length > 0) {
                        $alternate_name           = $alternate_name->item(0)->nodeValue;
                        $alternate_name           = preg_replace('/\(([^)]+)\)/i', '$1', $alternate_name);
                        $object->alternative_name = $alternate_name;
                    }
                } else if (preg_match('/<span id="fb-timeline-cover-name">([^<]+)<\/span>/i', $request->response, $name)) {
                    /*
                     * Segunda tentativa de pegar o nome do perfil
                     */
                    $object->name = html_entity_decode($name[1]);
                }
                unset($name);
            }
            /*
             * Likes do usuario
             */
            if (!empty($fields['likeds'])) {
                $pagesLiked = $domPath->query("//div[contains(@class,'pagesListData')]/span[@class='visible']/a[@href]");
                if (!empty($pagesLiked) and $pagesLiked->length > 0) {
                    $object->likeds        = new stdClass();
                    $object->likeds->pages = array();
                    foreach ($pagesLiked as $links) {
                        $url = $this->findOccurrence($links->getAttribute('href'), 'l.php?u=', 'last');
                        if (!preg_match('/l\.php/i', $url)) {
                            $username                = strtolower($this->findOccurrence($url, '/', 'last'));
                            $object->likeds->pages[] = (object) array(
                                'name' => utf8_decode($links->nodeValue),
                                'username' => $username,
                                'picture' => 'https://graph.facebook.com/' . $username . '/picture?type=large',
                                'url' => $url
                            );
                        }
                    }
                    $object->likeds->pages = (object) $object->likeds->pages;
                    $object->likeds->more  = 'https://www.facebook.com/browse/fanned_pages/?id=' . $object->id;
                }
                unset($pagesLiked);
            }
        }
        if (!empty($fields['picture'])) {
            $object->picture = 'https://graph.facebook.com/' . ((!empty($object->username)) ? $object->username : $object->id) . '/picture?type=large';
        }
        unset($request);
        return $object;
    }
    private function setFields() {
        $fields                        = array();
        $fields['facebook']            = array(
            'id' => 1,
            'type' => 1,
            'name' => 1,
            'username' => 1,
            'picture' => 1,
            'link' => 1,
            'verify' => 1,
            'likeds' => 1,
            'about' => 1,
            'cover' => 1
        );
        $fields['facebook']['page']    = array(
            'likes' => 1,
            'checkins' => 1,
            'rating' => 1,
            'email' => 1,
            'founded' => 1,
            'website' => 1
        );
        $fields['facebook']['profile'] = array(
            'alternative_name' => 1
        );
        $fields['instagram']           = array(
            'username' => 1,
            'biography' => 1,
            'website' => 1,
            'picture' => 1,
            'full_name' => 1,
            'is_private' => 1,
            'counts' => 1,
            'is_verified' => 1
        );
        $fields['twitter']             = array(
            'name' => 1,
            'screen_name' => 1,
            'location' => 1,
            'url' => 1,
            'description' => 1,
            'protected' => 1,
            'counts' => 1,
            'created_at' => 1,
            'time_zone' => 1,
            'verified' => 1,
            'lang' => 1,
            'badges' => 1,
            'profile' => 1
        );
        $fields['vk']                  = array(
            'id' => 1,
            'type' => 1,
            'username' => 1,
            'name' => 1,
            'photo' => 1,
            'description' => 1,
            'location' => 1,
            'website' => 1,
            'counts' => 1
        );
        $fields['vk']['group']         = array(
            'only_official' => 1
        );
        $fields['vk']['page']          = array(
            'text' => 1,
            'verified' => 1
        );
        $fields['vk']['profile']       = array(
            'birthday' => 1,
            'hometown' => 1,
            'current_city' => 1,
            'social' => 1,
            'education' => 1,
            'verified' => 1
        );
        if (empty($fields[$this->graph])) {
            return false;
        }
        $fields = $fields[$this->graph];
        if (!empty($_GET['fields'])) {
            $ex        = explode(',', $_GET['fields']);
            $newFields = array();
            foreach ($ex as $field => $val) {
                if (!empty($fields['page'][$val])) {
                    $newFields['page'][$val] = 1;
                } elseif (!empty($fields['profile'][$val])) {
                    $newFields['profile'][$val] = 1;
                } elseif (!empty($fields['group'][$val])) {
                    $newFields['group'][$val] = 1;
                } elseif (!empty($fields[$val])) {
                    $newFields[$val] = 1;
                }
            }
            unset($fields);
            $fields = $newFields;
            unset($newFields);
        }
        return $fields;
    }
    private function returnArray($object) {
        return json_decode(json_encode($object), true);
    }
    private function returnJson($object) {
        if ($this->header == true) {
            header('Content-type: application/json; charset=UTF-8');
        }
        return $this->json_format($object);
    }
    private function returnXml($object) {
        if ($this->header == true) {
            header("Content-Type: text/xml;  charset=UTF-8", true);
        }
        return $this->xml_encode($object);
    }
    private function requestGraph($forceUrl = false) {
        if (empty($this->username)) {
            return false;
        }
        $this->Curl->urlPage  = (!empty($forceUrl)) ? $forceUrl : 'https://www.facebook.com/' . $this->username . '?__nodl&_fb_noscript=1';
        $this->Curl->infoPage = parse_url($this->Curl->urlPage);
        if (empty($this->Curl->infoPage['host'])) {
            return;
        }
        $ch         = curl_init();
        $curl_array = array(
            CURLOPT_ENCODING => '',
            CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            CURLOPT_URL => $this->Curl->urlPage,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_POST => false,
            CURLOPT_REFERER => $this->Curl->referer,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_HEADER => true
        );
        if (!empty($this->Curl->agent)) {
            $curl_array[CURLOPT_USERAGENT] = $this->Curl->agent;
        }
        $curl_array[CURLOPT_HTTPHEADER] = array(
            'Host: ' . $this->Curl->infoPage['host'],
            'accept-language:pt-BR,pt;',
            'origin: ' . $this->Curl->infoPage['scheme'] . '://' . $this->Curl->infoPage['host'],
            'accept-encoding:gzip, deflate, sdch',
            'accept:text/html',
            'cache-control:max-age=0'
        );
        if ($this->Curl->interface != false && $this->Curl->interface != '127.0.0.1') {
            $curl_array[CURLOPT_INTERFACE] = $this->Curl->interface;
        }
        if ($this->graph == 'facebook') {
            $curl_array[CURLOPT_COOKIE] = 'noscript=1; locale=pt_BR; reg_fb_ref=' . urlencode($this->Curl->urlPage) . '; reg_fb_gate=' . urlencode($this->Curl->urlPage) . ';';
        } elseif ($this->graph == 'vk') {
            $curl_array[CURLOPT_COOKIE] = 'remixlang=73; ';
        }
        curl_setopt_array($ch, $curl_array);
        $response    = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($http_status == '301' || $http_status == '302') {
            preg_match('/Location:(.*?)\n/i', $response, $forceUrl);
            return $this->requestGraph(trim(array_pop($forceUrl)));
        }
        return (object) array(
            'http_code' => $http_status,
            'response' => $response
        );
    }
    private function findOccurrence($str, $search = '/', $find = 'last') {
        if ($find == 'last') {
            $return = substr(strrchr($str, $search), 1);
        } elseif ($find == 'first') {
            $return = stristr($str, $search, true);
        }
        if (empty($return)) {
            return $str;
        }
        return $return;
    }
    private function mb_convert($string) {
        return preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function($match) {
            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UTF-16BE');
        }, $string);
    }
    private function json_format($json) {
        if (!is_string($json)) {
            if (phpversion() && phpversion() >= 5.4) {
                return json_encode($json, JSON_PRETTY_PRINT);
            }
            $json = str_replace('\/', '/', json_encode($json));
        }
        $result      = '';
        $pos         = 0;
        $strLen      = strlen($json);
        $indentStr   = "   ";
        $newLine     = "\n";
        $prevChar    = '';
        $outOfQuotes = true;
        for ($i = 0; $i < $strLen; $i++) {
            $copyLen = strcspn($json, $outOfQuotes ? " \t\r\n\",:[{}]" : "\\\"", $i);
            if ($copyLen >= 1) {
                $copyStr  = substr($json, $i, $copyLen);
                $prevChar = '';
                $result .= $copyStr;
                $i += $copyLen - 1;
                continue;
            }
            $char = substr($json, $i, 1);
            if (!$outOfQuotes && $prevChar === '\\') {
                $result .= $char;
                $prevChar = '';
                continue;
            }
            if ($char === '"' && $prevChar !== '\\') {
                $outOfQuotes = !$outOfQuotes;
            } else if ($outOfQuotes && ($char === '}' || $char === ']')) {
                $result .= $newLine;
                $pos--;
                for ($j = 0; $j < $pos; $j++) {
                    $result .= $indentStr;
                    ;
                }
            } else if ($outOfQuotes && false !== strpos(" \t\r\n", $char)) {
                continue;
            }
            $result .= $char;
            if ($outOfQuotes && $char === ':') {
                $result .= ' ';
            } else if ($outOfQuotes && ($char === ',' || $char === '{' || $char === '[')) {
                $result .= $newLine;
                if ($char === '{' || $char === '[') {
                    $pos++;
                }
                for ($j = 0; $j < $pos; $j++) {
                    $result .= $indentStr;
                }
            }
            $prevChar = $char;
        }
        $result = preg_replace('/\"([0-9]+)\"\,/i', '$1,', $result);
        return $result;
    }
    private function xml_encode($var, $indent = false, $i = 0) {
        $version = "1.0";
        if (!$i) {
            $data = '<?xml version="1.0"?>' . ($indent ? "\r\n" : '') . '<root vartype="' . gettype($var) . '" xml_encode_version="' . $version . '">' . ($indent ? "\r\n" : '');
        } else {
            $data = '';
        }
        foreach ($var as $k => $v) {
            $data .= ($indent ? str_repeat("\t", $i) : '') . '<var vartype="' . gettype($v) . '" varname="' . htmlspecialchars($k) . '"';
            if ($v == "") {
                $data .= ' />';
            } else {
                $data .= '>';
                if (is_array($v) || is_object($v)) {
                    $data .= ($indent ? "\r\n" : '') . $this->xml_encode($v, $indent, ($i + 1)) . ($indent ? str_repeat("\t", $i) : '');
                } else {
                    $data .= htmlspecialchars($v);
                }
                $data .= '</var>';
            }
            $data .= ($indent ? "\r\n" : '');
        }
        if (!$i) {
            $data .= '</root>';
        }
        return $data;
    }
}
$Api = new Api();
$Api->setVar('header', true);
$Api->setVar('graph', ((!empty($_GET['graph'])) ? $_GET['graph'] : 'facebook'));
$Api->setVar('output', ((!empty($_GET['output'])) ? $_GET['output'] : 'json'));
if (!empty($_GET['username'])) {
    $Api->setVar('username', $_GET['username']);
    $Api->setVar('Curl::interface', false);
    $Api->setVar('Curl::agent', 'Android');
    $Api->setVar('Curl::referer', 'https://www.facebook.com/');
    $graph = $Api->graph();
} else {
    $graph = $Api->setOutput($Api->lang[3], 1);
}
print_r($graph);