=== Plugixa Exam ===
Contributors: zakaria04, freemius
Tags: exam, quiz, test, assessment, elearning
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exams for WordPress: a question bank, an exam builder with random pools, a server-timed runner with autosave, automatic marking and PDF certificates.

== Description ==

🎓 Turn WordPress into somewhere people can actually sit an exam with **Plugixa Exam** - a question bank, an exam builder with sections and random pools, a runner whose clock lives on the server, automatic marking and verifiable PDF certificates.

📚 Read the [**Documentation**](https://www.plugixa.com/documentation/plugixa-exam)
🌐 Visit the official website [**Plugixa**](https://www.plugixa.com/)
✉️ Questions or support requests: [**Contact us**](https://www.plugixa.com/contact)

[__Documentation__](https://www.plugixa.com/documentation/plugixa-exam) | [__Contact__](https://www.plugixa.com/contact) | [__Support Forum__](https://wordpress.org/support/plugin/plugixa-exam/) | [__Upgrade to Pro!__](https://www.plugixa.com/plugins/plugixa-exam#pricing)

Run your first exam in **3 simple steps**:

1. Write questions into the bank - organised by a subject tree and by tags
2. Build an exam from sections - pick questions by hand, or describe a pool and let each candidate draw their own paper
3. Publish it - people sit it, the plugin marks what it can and hands you the rest

Records live in the plugin's **own database tables**, not in posts and post meta, so the question bank, the attempts list and the dashboard stay fast as your history grows.

### Exams That Behave Like Exams

The timer is server-side, the paper is frozen when a candidate starts, and answers autosave as they go.

✅ **Question bank** - seven question types, with explanations, rubrics and pictures
✅ **Exam builder** - sections that are a fixed list or a pool with rules, with shuffling
✅ **Runner** - server-side timing, autosave, pause and resume, and it works without JavaScript
✅ **Marking** - automatic on submission, with a queue for what needs a person
✅ **Certificates** - awarded on a pass, rendered to PDF and verifiable by QR code

= Key Features of Plugixa Exam =

* Fast, Material UI-based admin application
* Seven question types: multiple choice with one answer or several, true/false, short answer, essay, fill in the blanks and picture choice
* Short answers matched case-insensitively, numerically with a tolerance, or against a regular expression
* An explanation on every question, shown after marking, and a rubric on essays
* Pictures in questions and on options, uploaded from the question editor, with a description for people who cannot see them
* Exams built from sections: a fixed list, or a pool drawn by subject, tag, difficulty and type
* Questions and their options shuffled independently
* Server-side timer, a paper frozen at the start, autosave, pause and resume
* A runner that degrades to an ordinary form post without JavaScript
* Automatic marking on submission, and a marking queue with the rubric beside the answer
* Results released immediately, when the exam closes, on a date you choose, or only when you say so
* Access open to everybody, by role, by access code, or to a guest who gives a name and an email
* Attempt caps and cooldowns enforced on the server
* Import from CSV, Aiken, GIFT, Moodle XML and QTI; export to CSV, Moodle XML and QTI
* Certificates awarded automatically, numbered, rendered as a real PDF, with a public verification page
* A learner dashboard, plus blocks and shortcodes for assigned exams, results and certificates
* An exam catalogue that shows a signed-in learner where they stand on every exam
* Result emails with five editable templates
* Five seeded roles, a schedule calendar and a dashboard
* GDPR export and erasure through WordPress's own privacy tools
* Translation-ready, with right-to-left support
* No CDN - fonts are served from your own site

= Plugixa Exam Pro Features =

* Seven more question types - ordering, matching, extended matching, hotspot, image matching, range and file upload
* A drag-and-drop certificate designer, as many designs as you like, and a different certificate per exam
* Live invigilation with a real-time board
* Analytics with item analysis and scheduled reports
* A gradebook with letter grades and weighted transcripts
* AI question drafting with your own API key
* Bridges into Tutor LMS, LearnDash and LifterLMS
* Selling exams through WooCommerce - access for a period, attempts, renewals and refunds
* Multilingual content - one exam in several languages, with one result set
* Cohorts, prerequisites and custom fields
* Practice mode with per-question feedback, and LaTeX rendering
* Version history, integrity signals and opt-in public leaderboards
* Webhooks, LTI 1.3 and API tokens
* A demo dataset
* Priority support

[Upgrade to Plugixa Exam Pro](https://www.plugixa.com/plugins/plugixa-exam#pricing)

= ⏱️ A Clock Nobody Can Change =

The deadline is computed and stored on the server when an attempt starts, and every write checks it. Changing the clock on the candidate's machine does nothing: the countdown in the browser is decoration.

= 🧊 A Paper Frozen at the Start =

The questions, their order, their points and the runner's settings are frozen into the attempt when it starts. Editing the exam half-way through does not change what somebody is already answering. A pool draws when the attempt starts, so each candidate gets their own paper, and a reload returns the same one.

= ✍️ Marked on Submit, Released on Your Terms =

Everything that can be marked automatically is marked on submission. What needs a person - essays, and anything else you have chosen to mark by hand - goes into a queue with the rubric beside it. A score the release policy is still withholding is never shown, only that the paper was submitted.

= 📜 Certificates People Can Actually Use =

A certificate is awarded when somebody passes and their marking is finished, numbered to a scheme you choose, and rendered as a real PDF rather than a web page they have to print. Its QR code opens a public page that says whether the certificate is valid, withdrawn or expired without disclosing the mark. Arabic and Hebrew names are shaped and ordered properly. The free version gives you one certificate design, fully editable.

= 🖥️ On Your Own Site, in Your Own Theme =

The catalogue, the exam page, the runner, the result and the learner's dashboard at /my-exams/ are server-rendered inside your theme. Pages that carry one person's own history are never cached; a first-time visitor's catalogue and exam pages stay cacheable.

= 🔐 Privacy =

The plugin stores what an exam plugin has to store: who sat what, when, what they answered and what they scored. It sets no cookies for tracking; the only cookie it sets is a signed identifier for a guest who starts an exam without an account, set when they press Start.

### 👨‍💻 Author

[**Plugixa Exam**](https://www.plugixa.com/plugins/plugixa-exam) is developed by [**Plugixa**](https://www.plugixa.com/), building focused, well-documented WordPress plugins with an emphasis on performance, clean code and correct data.

### 🛟 Documentation & Support

Plugixa Exam is built to be usable without a manual, but it is documented in full.

👉 Read the [Documentation](https://www.plugixa.com/documentation/plugixa-exam) - getting started, the question bank, the exam builder, marking, certificates, blocks and shortcodes
👉 Ask on the [WordPress.org support forum](https://wordpress.org/support/plugin/plugixa-exam/)
👉 Or [contact us directly](https://www.plugixa.com/contact)
👉 Browse more plugins at [plugixa.com](https://www.plugixa.com/)

= Shortcode Usage =

The catalogue of exams:
`[plugixa_exam_catalogue]`

One exam:
`[plugixa_exam id="12"]`

The learner's lists:
`[plugixa_exam_my_exams]` `[plugixa_exam_my_results]` `[plugixa_exam_my_certificates]`

== Installation ==

= MINIMUM REQUIREMENTS =

* WordPress 6.5 or greater
* PHP version 8.2 or greater

= AUTOMATIC INSTALLATION (EASIEST WAY) =

1. In your WordPress admin, go to Plugins > Add New
2. Search for "Plugixa Exam"
3. Click Install Now, then Activate
4. Open "Plugixa Exams" in the admin menu. Create a subject, write a question, build an exam

= MANUAL INSTALLATION =

1. Download the plugin ZIP
2. Go to Plugins > Add New > Upload Plugin, choose the ZIP and click Install Now
   (or upload the `plugixa-exam` folder to `/wp-content/plugins/` over FTP)
3. Activate the plugin through the Plugins menu. The database tables are created on activation
4. Open "Plugixa Exams" in the admin menu

= GIVING OTHER PEOPLE ACCESS =

Administrators have access by default. The plugin seeds five roles of its own - Exam Admin, Author, Grader, Proctor and Viewer - and the Roles screen shows what each may do in every area. Assign one to the people who write, mark or invigilate.

== Frequently Asked Questions ==

= Q. Can somebody cheat by changing their computer's clock? =

No. The deadline is computed and stored on the server when the attempt starts, and every write checks it. The countdown in the browser is decoration.

= Q. What happens if I edit an exam while somebody is sitting it? =

Nothing, for them. The set of questions, their order, their points and the runner's settings are frozen into the attempt when it starts. Your edit applies to the next person who begins.

= Q. Does a pool draw a different paper for each candidate? =

Yes. A pool section draws when the attempt starts, and that draw is then frozen for that candidate.

= Q. Can people take an exam without an account? =

Yes, if you set the exam's access to guest. They give a name and an email, and the plugin identifies them with a signed cookie. No PHP session is used.

= Q. Does the runner work without JavaScript? =

Yes. It degrades to an ordinary form post, and the learner's pages are server-rendered.

= Q. Does it store exams as posts or custom post types? =

No. It uses its own database tables, created on activation and removed on uninstall. Questions, attempts and answers are relational data, and storing them as posts and post meta makes every list and report slower as the history grows.

= Q. What is the difference between the free and Pro versions? =

The free version is a complete exam plugin, neither time-limited nor feature-locked. The premium version is a separate download that adds features; nothing in the free build is disabled, and the free build contains no premium code to unlock. See the [Pro features](https://www.plugixa.com/plugins/plugixa-exam#pricing).

One number is worth stating plainly rather than leaving you to find it: the free version keeps one certificate design. That is not a locked second design - the designer that draws them is simply not in the free download - and the one you have is fully editable, layout included. Everything a certificate does is free: it is awarded, numbered, rendered and verified exactly as it is in premium.

= Q. Is it translation-ready? =

Yes, and that includes the parts that are easy to miss. The admin screens, the exam runner your candidates actually sit, the block editor controls, the front-end templates and the notification emails all read from the same text domain (plugixa-exam), so one translation covers the whole plugin rather than the half a visitor never sees.

Translations are served by translate.wordpress.org. Once a language has been translated there, WordPress installs it as a language pack and keeps it up to date, with nothing for you to do; until then the plugin reads in English. The plugin ships its template (languages/plugixa-exam.pot) for anybody who would rather translate it themselves: a catalogue you place in wp-content/languages/plugins/ is used as it stands, and tools such as Loco Translate work normally.

Two details worth knowing. Dates and numbers follow the site's locale and timezone, not the reader's browser - so an exam that opens at 09:00 says 09:00 to everybody. And notification emails are composed in the language of the person receiving them where the plugin can tell who that is: a learner with a French account gets French dates and a French pass or fail, even when the marker who released the result reads the site in English. The subject and body wording itself comes from the template you edit under Notifications, so that is in whichever language you write it in.

= Q. Can one exam be offered in several languages? =

Translating the plugin and translating your questions are different jobs: the first is a gettext catalogue, the second is a second version of your content. In the free version a bilingual site needs one exam per language. Premium's multilingual content serves one exam in several languages, so everybody sits the same paper and lands in the same results and item analysis.

= Q. Does it need any external service? =

Not to run an exam. Everything is read from and written to your own WordPress database. Freemius handles licensing and updates, and three premium features contact nothing until an administrator has entered an address or a key. All four are described under "External services" below.

= Q. Where is the source for the JavaScript the plugin loads? =

= Q. Where can I find the documentation? =

Full documentation - getting started, the question bank, the exam builder, marking, certificates, blocks and shortcodes - is at https://www.plugixa.com/documentation/plugixa-exam

== External services ==

This plugin connects to no external service of its own choosing. It loads no fonts from a
CDN — the KaTeX fonts used by the premium maths rendering are bundled — and it never
contacts plugixa.com: our address appears in the plugin header and in copyright notices, and
no code calls it.

Four services are described below. Freemius handles licensing and updates. The other three
are premium features that contact nothing until an administrator has entered an address or a
key: on a site where nobody has configured them no request is ever made.

The plugin is published by Plugixa. Our policies are here:
Terms of Service: https://www.plugixa.com/terms-of-service
Privacy Policy: https://www.plugixa.com/privacy-policy

= Freemius (free and premium) =

What it is and why: Freemius, Inc. provides licensing, plugin updates and checkout, plus —
only if you opt in — anonymous usage diagnostics.

What is sent and when: on activation you are shown an opt-in screen. If you skip it, no
identifiable data is sent. If you opt in, or when you activate or manage a licence or make a
purchase, data such as your site URL, WordPress and PHP versions, active theme and plugins,
and the email address used at opt-in may be transmitted. This happens on activation, on
update checks, and on licence actions.

Terms of Service: https://freemius.com/terms/
Privacy Policy: https://freemius.com/privacy/

= AI question writing (premium only, and only with an API key you supply) =

What it is and why: the plugin can draft questions from source material you paste — a
syllabus, a page of notes, a chapter — so you have something to edit instead of a blank
form. To do that it sends your material to a language-model provider that you choose and
pay for directly.

What is sent and when: at the moment somebody presses Generate, and never otherwise, the
plugin sends the text you pasted together with the instruction built from your choices on
that screen (how many questions, which types, what difficulty, which language). Nothing
else is sent: not your site address, not who you are, not any exam, attempt, mark or
learner data. The reply is shown to you for review and nothing is written to your question
bank until you accept it.

There is no default key and no key is bundled. Until an administrator enters one under
Settings, AI, this feature makes no request at all and the screen says so. The key is
stored on your own site, is never included in any response the plugin sends to a browser,
and is transmitted only to the endpoint you configured — over https, unless that endpoint
is on your own machine or private network.

Whose service it is: whichever provider you choose and hold an account with. The plugin
ships two ways to connect and no relationship with either company.

OpenAI, and any service that speaks its chat-completions API — which includes Azure OpenAI,
OpenRouter, Groq, Together, and local runtimes such as Ollama and LM Studio, reached by
changing the endpoint:
Terms of Use: https://openai.com/policies/terms-of-use
Privacy Policy: https://openai.com/policies/privacy-policy

Anthropic (Claude):
Commercial Terms of Service: https://www.anthropic.com/legal/commercial-terms
Privacy Policy: https://www.anthropic.com/legal/privacy

If you point the endpoint at a different service, its terms and privacy policy are the ones
that apply, and this plugin has no relationship with it.

= Webhooks (premium only, and only to an address you enter) =

What it is and why: if you register a webhook endpoint, the plugin sends an HTTP POST to it
when an attempt is submitted, graded or re-marked, when a certificate is issued, or when
someone's access to an exam starts or ends, so another system of yours can react.

What is sent and when: the event name and the record it concerns — an attempt (exam, score,
result, the candidate's user ID and a guest's email address), a certificate (number,
recipient's name, user ID, verification link) or an access grant (exam, user ID, dates, what
granted it) —
signed with a secret the plugin generates, at the moment the event happens. It is sent only to
addresses an administrator has entered. There is no default endpoint, private and reserved
network addresses are refused unless you deliberately allow them, and no data leaves the
site until you add one.

Whose service it is: yours, or whichever third party you point it at. This plugin has no
relationship with it and no endpoint of its own.

= LTI 1.3 (premium only, and only with a platform you register) =

What it is and why: if you register a learning platform (an LMS such as Moodle or Canvas),
the plugin lets its users launch into an exam here and sends their score back.

What is sent and when: a launch returns the learner's browser to the platform's sign-in URL
with your client ID and the hint the platform supplied. The plugin fetches the platform's
signing keys from the JWKS URL you supply, to verify the launch. When an attempt is graded
or re-marked, and once results are released, it requests an access token from the
platform's token URL (your client ID, signed by this site) and posts to the grade endpoint
the launch supplied: the platform's identifier for the learner, the score and its maximum,
the marker's comment and the time.

Whose service it is: yours, or your institution's. The URLs are the ones you enter; the
plugin ships no default platform and contacts nothing until you register one.

== Screenshots ==

1. Dashboard - the question bank, exams, attempts and the papers waiting to be marked, on one screen
2. Question bank - every question, organised by a nestable subject tree and by tags
3. Question editor - writing a multiple-choice question
4. Exam builder - a paper built from sections, with shuffling and points per section
5. Pool rules - describe a pool and each candidate draws their own paper, frozen when they start
6. Attempts - who sat what, when, what they scored and what state the paper is in
7. Marking - a submitted paper, showing what was marked automatically and what still needs a person
8. Analytics - item analysis with difficulty and discrimination per question (Premium)
9. Certificates - issued on a pass, rendered to PDF and verifiable through a QR code
10. Live monitor - who is sitting an exam right now, and how long each has left (Premium)
11. Calendar - the opening and closing windows of every exam, by date
12. Roles - five seeded roles and what each may do in every area
13. Subjects - a tree as deep as the syllabus, used for pools and for result breakdowns
14. App manager - switch off the capabilities a site does not use, without deleting their data
15. Exam catalogue - the public list of exams, showing a signed-in learner where they stand
16. Exam page - what a learner sees before starting: the description, the pass mark and where they stand
17. Exam runner - one question at a time, with a question navigator, flagging, a progress bar and autosave (the practice exam shown is Premium)
18. Result page - the score, pass or fail, the certificate to download and every answer reviewed
19. Certificate check - the public page behind the QR code, saying whether a certificate is genuine
20. Learner portal - what has been assigned, what was scored and what was earned

== Changelog ==

= 1.1.3 =
* An exam set to refuse blank answers now refuses them: a paper with an unanswered question is not taken while there is time left. Before, the setting was stored and nothing enforced it.
* Updating a question through the REST API with only some of its fields no longer resets the others. A question could be unpublished this way, and an unpublished question is left off every new paper.
* When results that were held back are released, each candidate whose paper is marked is told.
* Two copies of the plugin installed side by side no longer log PHP warnings, and deleting one of them keeps your exams until the last copy is deleted.
* A number saved in Settings is stored as a number.
* Every REST route now states its permission check where the route is registered, as a call to WordPress's own current_user_can(). Who may do what is unchanged.
* The download no longer carries development files that came with bundled libraries, and a licence file inside one of them has a .txt name.

= 1.1.2 =
* Requires PHP 8.2 or later. The library that draws the QR code on certificates was brought up to date, and its current version needs it.
* Security: only the candidate can save, pause or hand in a paper that is in progress. A role allowed to view attempts could do so on somebody else's paper; it can still read results, and nothing more.
* Security: the colours in Settings are checked to be colours when they are saved and again when they are used on a page.
* Security: answers sent from the exam form that works without JavaScript are cleaned for their question type before any other code receives them.
* If a database table cannot be created when the plugin is activated or updated, the plugin now says so: a notice names the table and offers "Try again". It no longer records the update as done.
* Fixed: on a certificate PDF, the QR code and other pictures were drawn in the top-left corner instead of where the design puts them, and a background on a landscape page pushed the code onto a second sheet.
* Fixed: an essay answer containing <, > or & could be stored differently from what the candidate typed. It is now kept exactly as typed.
* Fixed: updating a certificate design through the REST API with only some of its fields emptied the others.
* Fixed: pressing Start on an exam that had just been deleted led to an address with nothing at it. It now goes to the list of exams.
* The WordPress.org download no longer bundles translation files; WordPress installs translations from translate.wordpress.org as they are approved there. The premium download still includes French, Arabic, Spanish and German.
* Premium: AI drafting no longer sends your site's address to the AI provider in the request's User-Agent.
* Premium: the leaderboard block's settings are translated.

= 1.1.1 =
* File answers are saved through WordPress's own uploader, so whatever a site attaches to uploads — a security scanner, a storage quota — now applies to handed-in work too. The files stay in their private folder and never appear in the media library.
* Fixed: after the plugin was deactivated or deleted, its addresses — /exams/, /my-exams/, a result, a certificate check — went on answering with the site's home page instead of "not found".
* Fixed: activating the plugin on a site that also runs WooCommerce wrote two "translation loading was triggered too early" notices to the debug log.
* The plugin's translations are registered the way current WordPress expects, with no change to which languages load.
* The download no longer carries a build script from a bundled library.

= 1.1.0 =
* New in premium: sell exams through WooCommerce. Switch on "Enrolment required" on an exam and only people who have bought it, or been given access by you, may sit it. Create the product from the exam's new Sales tab, or add an "Exam access" tab to any simple or variable product. Each purchase can last a number of days and include a number of attempts; a variable product can sell 30 days and 90 days at different prices.
* Access follows the order: it starts when the order is paid, stops on a refund, a cancellation or while it is on hold, and comes back if the order is paid again or restored from the trash. What a customer bought is copied onto their order, so later changes to the product never reach back into it.
* Renewing early runs on from the current end date instead of wasting the days left, and is usable at once.
* The exam page shows a price and a Buy button to anybody who may not sit it yet, the catalogue shows the price on the card, the product page lists what is included, and the order confirmation, the order emails and a new My exams tab in the customer's account all link straight into the exam.
* A cart holding an exam requires an account at checkout; exam-only orders complete themselves once paid.
* Refunding a purchase withdraws the certificates it paid for (a setting, on by default); the certificate can be restored.
* New screen: People → Enrolments, to see who holds access to which exam, grant access by hand, change the end date or the attempts, revoke and restore. A new Enrolments permission area in Roles.
* An email reminds learners a few days before their access ends, unless they have already renewed.
* New webhook events: enrolment.granted and enrolment.revoked.
* New webhook event: attempt.regraded, sent when a marker changes the result of an attempt that was already graded, with the result before and after. attempt.graded still fires once per attempt.
* Certificates follow a re-mark: turning a fail into a pass awards the certificate, turning a pass into a fail withdraws it (the verification page then says so), and passing again restores the same certificate with its number. A certificate an administrator withdrew stays withdrawn.
* The Withdraw dialog has a field for the reason it asks for, and the certificate list shows why each certificate was withdrawn.
* LMS bridge: verified end to end against Tutor LMS, LifterLMS and LearnDash 5.1.10.
* LMS bridge: a linked course is completed when a marker turns a fail into a pass, and not before the exam's results are released.
* LMS bridge: a learner who loses access to an exam partway through a paper — unenrolled from the linked course, for example — can no longer save or submit it. Restoring their access lets them carry on where they stopped.
* LMS bridge: the course picker is on the exam's Access tab, searches every course, and warns when the exam's settings mean the link would keep nobody out. A link is checked when it is saved: the course must be published, and a nominated lesson must belong to it.
* LearnDash: an exam limited to a course's learners admits only people LearnDash itself still lets in — no longer anyone whose access has expired or whose group has ended. An Open course cannot be linked, because nobody enrols on it.
* LTI: the score sent back to the LMS gradebook follows a marker's corrections, and waits until the exam's results are released.
* The plugin's entry in the WordPress admin menu is now called "Plugixa Exams".
* An "Exam access" box on WooCommerce's order screen shows what an order holds, with a button to re-sync it.
* Works with the WooCommerce Checkout block and with high-performance order storage. Needs WooCommerce 9.0 or later.
* Fixed: an exam that several modules each assigned to a learner was listed once per module on their My exams page.
* Fixed: a learner's My certificates page listed a certificate before the exam's results were released.
* Fixed: passing an exam linked to a LearnDash course did not complete the course unless it had no lessons.
* Fixed: learners launched from an LMS through LTI 1.3 were sent back to the exam page after pressing Start, and each press used up an attempt. The attempt limit now applies to them as to everyone else.
* Fixed: a damaged LTI signing key made submitting fail for learners launched from an LMS. It now only stops the score being sent back.
* Fixed: with Tutor LMS active, an attempt whose number matched the ID of a Tutor course could not be saved or submitted.
* Fixed: "Who may sit this" said nobody could sit an exam that a linked course lets people into.

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.1.3 =
A maintenance release. Nothing to do after updating.

= 1.1.2 =
Requires PHP 8.2 or later, so check your PHP version before updating. Includes a security fix: only the candidate can save or hand in a paper that is in progress.

= 1.1.1 =
Fixes two problems around activating and removing the plugin. Nothing to do after updating.

= 1.1.0 =
Premium adds selling exams through WooCommerce. Nothing changes on an existing exam until you switch on "Enrolment required" for it. On a paid exam, set Max attempts to 0 so each purchase can carry its own attempts.

= 1.0.0 =
Initial release.
