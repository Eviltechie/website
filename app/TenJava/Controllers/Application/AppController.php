<?php
namespace TenJava\Controllers\Application;

use App;
use Config;
use Github\Client;
use Input;
use Mail;
use Queue;
use Redirect;
use TenJava\Controllers\Abstracts\BaseController;
use TenJava\Controllers\ErrorController;
use TenJava\Exceptions\UnauthorizedException;
use TenJava\Models\Application;
use Validator;
use View;

class AppController extends BaseController {

    /**
     * @var \TenJava\Controllers\ErrorController
     */
    private $errorController;

    public function  __construct(ErrorController $errorController) {
        parent::__construct();
        $this->beforeFilter('AuthenticationFilter');
        $this->errorController = $errorController;
    }

    public function showApplyJudge() {
        $this->setActive("sign up");
        $this->setPageTitle("Judge application");
        return View::make("pages.forms.judge", array("username" => $this->auth->getUsername()));
    }

    public function showApplyParticipant() {
        $this->setActive("sign up");
        $this->setPageTitle("Registration");
        return View::make("pages.forms.participant", array("username" => $this->auth->getUsername()));
    }

    public function declineJudgeApp() {
        $app = Application::findOrFail(Input::get("app_id"));
        $username = $app->gh_username;
        $gmail = $app->gmail;
        Mail::queue(array('text' => 'emails.judge.decline'), array("user" => $username), function ($message) use ($gmail) {
            $message->from('tenjava@tenjava.com', 'ten.java Team');
            $message->to($gmail)->subject('Your recent judge application');
        });
        $app->delete();
        return Redirect::back();

    }

    public function acceptJudgeApp() {
        $app = Application::findOrFail(Input::get("app_id"));
        $username = $app->gh_username;
        $gmail = $app->gmail;
        Mail::queue(array('text' => 'emails.judge.accept'), array("user" => $username), function ($message) use ($gmail) {
            $message->from('tenjava@tenjava.com', 'ten.java Team');
            $message->to($gmail)->subject('Your recent judge application');
        });
        $app->delete();
        return Redirect::back();
    }

    public function listApps($filter = null) {
        $this->setPageTitle("Application list");
        $this->setActive("App list");

        $viewData = array(
            "append" => array(),
            "apps" => null,
            "fullAccess" => false
        );

        if ($this->auth->isAdmin()) {
            $viewData["fullAccess"] = true;
        }

        switch($filter) {
            case "judges":
                $viewData['apps'] = Application::with(['timeEntry','commits'])->where('judge', true)->paginate(5);
                //$viewData['append'] = array("judges" => "1");
                break;
            case "normal":
                $viewData['apps'] = Application::with(['timeEntry','commits'])->where('judge', false)->paginate(5);
                //$viewData['append'] = array("normal" => "1");
                break;
            case "unc":
                $viewData['apps'] = Application::with(['timeEntry','commits'])->has("timeEntry", "=", "0")->where('judge', false)->paginate(5);
                //$viewData['append'] = array("unc" => "1");
                break;
            case "conf":
                $viewData['apps'] = Application::with(['timeEntry','commits'])->has("timeEntry", ">", "0")->where('judge', false)->paginate(5);
                //$viewData['append'] = array("conf" => "1");
                break;
            case "t1":
                $viewData['apps'] = Application::whereHas('timeEntry', function($q) {$q->where('t1', true);})->paginate(5);
                break;
            case "t2":
                $viewData['apps'] = Application::whereHas('timeEntry', function($q) {$q->where('t2', true);})->paginate(5);
                break;
            case "t3":
                $viewData['apps'] = Application::whereHas('timeEntry', function($q) {$q->where('t3', true);})->paginate(5);
                break;
            case 'turnedup':
                $viewData['apps'] = Application::with(['timeEntry','commits'])->has("commits", ">", "0")->where('judge', false)->paginate(5);
                break;
            case "search":
                $searchQuery = Input::get("search");
                $viewData['apps'] = Application::search(explode(" ", $searchQuery))->paginate(5);
                $viewData['append'] = array("search" => Input::get("search"));
                $viewData['keywords'] = $searchQuery;
                break;
            default:
                $viewData['apps'] = Application::with(['timeEntry','commits'])->paginate(5);
                break;
        }
        return View::make("pages.staff.app-list")->with($viewData);

    }

    public function processApplication($type) {
        $dupeApp = false;
        if (Application::where("gh_id", $this->auth->getUserId())->first() != null) {
            $dupeApp = true;
        }
        if ($type !== "participant" && $type !== "judge") {
            return $this->errorController->badRequest("Invalid application type was supplied.");
        }
        if ($type === "participant") {
            $validator = Validator::make(
                array(
                    'dbo' => Input::get("dbo"),
                    'twitch' => Input::get("twitch"),
                    "dupeApp" => !$dupeApp,
                    'closed' => null
                ),
                array(
                    'dbo' => 'required|max:255',
                    'twitch' => 'max:255',
                    "dupeApp" => "accepted",
                    "closed" => "required"
                ),
                array(
                    'dupeApp.accepted' => "An application/registration entry already exists for this user.",
                    'closed.required' => "Sorry, participant registrations have closed."
                )
            );
            if ($validator->fails()) {
                return Redirect::to("/register/participant")->withErrors($validator)->withInput();
            }
            $app = new Application();
            $app->gh_username = $this->auth->getUsername();
            $app->github_email = json_encode($this->auth->getEmails());
            $app->judge = false;
            $app->gh_id = $this->auth->getUserId();
            $app->dbo_username = Input::get("dbo");
            if (!Input::has("twitch")) {
                $app->twitch_username = "USER_REJECTED"; //field not nullable so this will have to do.
            } else {
                $app->twitch_username = Input::get("twitch");
            }
            $app->save();
            return View::make("pages.result.thanks.participant")->with(array("username" => $this->auth->getUsername()));
        } else {
            $client = $this->getUserApiClient();
            $numRepos = count($client->repositories($this->auth->getUsername()));
            $githubTest = ($numRepos != 0);
            $validator = Validator::make(
                array(
                    'dbo' => Input::get("dbo"),
                    'mc' => Input::get("mcign"),
                    'gmail' => Input::get("gdocs"),
                    'irc' => Input::get("irc"),
                    'githubAcceptable' => ($githubTest) ? "OK" : "",
                    "dupeApp" => !$dupeApp,
                    'closed' => null
                ),
                array(
                    'dbo' => 'required|max:255',
                    'mc' => 'required|max:16',
                    'irc' => 'required|max:255',
                    'gmail' => 'required|email|max:255',
                    'githubAcceptable' => 'required',
                    "dupeApp" => "accepted",
                    "closed" => "required"
                ),
                array(
                    'githubAcceptable.required' => 'Sorry, you do not meet the minimum requirements for a judge.',
                    'mc.max' => 'Invalid Minecraft username specified.',
                    'mc.required' => 'No Minecraft username specified.',
                    'dupeApp.accepted' => "An application/registration entry already exists for this user.",
                    'closed.required' => "Sorry, judge applications have closed."
                )
            );
            if ($validator->fails()) {
                return Redirect::to("/register/judge")->withErrors($validator)->withInput();
            }
            $app = new Application();
            $app->gh_username = $this->auth->getUsername();
            $app->github_email = json_encode($this->auth->getEmails());
            $app->judge = true;
            $app->gh_id = $this->auth->getUserId();
            $app->dbo_username = Input::get("dbo");
            $app->irc_username = Input::get("irc");
            $app->mc_username = Input::get("mcign");
            $app->gmail = Input::get("gdocs");
            $app->save();
            return View::make("pages.result.thanks.judge")->with(array("username" => $this->auth->getUsername()));
        }
    }

    public function addUserRepo($username) {
        //$client = new \Github\Client();
        //$client->authenticate("tenjava", Config::get("gh-data.pass"), \GitHub\Client::AUTH_HTTP_PASSWORD);
        //$repo = $client->api('repo')->create($username, 'Repository for a ten.java submission.', 'http://tenjava.com', true, null, false, false, false, null, true);
        //$client->api('repo')->collaborators()->add("tenjava", $username, $username);
    }

    public function deleteUserEntry() {
        $id = Input::get("app_id");
        $app = Application::findOrFail($id);
        /** @var $app Application */
        $te = $app->timeEntry;
        /** @see \TenJava\QueueJobs\TimeRemovalJob */
        if ($te !== null) {
            Queue::push('TenJava\\QueueJobs\\TimeRemovalJob', array('username' => $app->gh_username, 't1' => $te->t1, "t2" => $te->t2, "t3" => $te->t3));
            $te->delete();
        }
        $app->delete();
        return Redirect::back();
    }

} 
