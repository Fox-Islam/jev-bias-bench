# Jev Bias Bench

Does [Jev](https://github.com/Fox-Islam/typesafe-sdk-php) decide differently about the same case when the
only thing that changes is who the person is?

Laravel + Vue + Sail. It generates people who differ in one attribute and nothing else, puts the same
decision to Jev about each of them through the [PHP SDK](https://github.com/Fox-Islam/typesafe-sdk-php),
and reports which swaps moved the answer — with the measurements that say whether any of it is real.

```sh
cp .env.example .env && composer install
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan bench:seed-canonical     # the run behind FINDINGS.md
./vendor/bin/sail npm install && ./vendor/bin/sail npm run build
```

That gives a populated dashboard at `http://localhost:8088` with no API key and nothing spent: the
11,984-call run the findings are drawn from ships with the repository, every call and every answer, in
`database/seed/canonical-run.sql.gz`. `bench:analyse --run=deep` recomputes the whole report from it.

To run your own, put `TYPESAFE_API_KEY` in `.env` and:

```sh
./vendor/bin/sail artisan bench:run --profile=pilot
./vendor/bin/sail artisan bench:analyse
```

[FINDINGS.md](FINDINGS.md) is what that run said. `docs/index.html` is the same report as a single
static page, which is what GitHub Pages serves from the `docs/` folder.

## The design

Two things decide whether a bias benchmark says anything: what it compares, and what it compares the
answer against.

### What it compares

**Counterfactual pairs, one attribute at a time.** A handful of *anchor* people are generated, and for
every attribute level one copy of each anchor that differs in that attribute and in nothing else — same
case, same background, same name, down to the byte. Only a swap of name culture or gender redraws the
name, because a name is how those two are carried.

Every comparison is a person against their own anchor. Variation between people, which is most of the
variation in a randomised cohort, never enters the measurement. That is what makes an effect visible at
a few hundred calls instead of a few hundred thousand.

The alternative design is there too (`--profile=factorial`): draw every attribute independently, laid
out on a near-orthogonal array so no two attributes arrive correlated by accident. It is far weaker per
call and exists for the question a one-at-a-time sweep cannot reach — what happens when attributes
combine.

### What it compares against

**The model's own noise, measured.** A subset of calls are repeated unchanged. The spread of those
repeats is how much Jev moves when nothing does, and it is the distribution every difference is tested
against. No assumption that answers are normally distributed — they are bounded in [0, 1] and pile up at
the ends, so a t-test on them is a guess in a lab coat.

**Anchors are asked repeatedly and averaged.** Every swap in a base is compared with the same anchor, so
one unlucky anchor answer would push every comparison built on it the same way. An early build of this
benchmark did exactly that: nine of its top ten "findings" came out positive together. Averaging the
anchor over several asks is what fixed it.

**A negative control that costs nothing.** Under the `name_only` condition the model is shown a name and
nothing else, so swapping religion or disability changed nothing it could read. Those comparisons must
come out null. The rate at which they do not is this benchmark's false-positive rate, measured rather
than claimed, and it is on the dashboard next to the findings.

**A validity check on every question.** Each scenario has weak, mid and strong versions of the same case.
A question that answers the same way whether the candidate has three years or nine is not measuring
anything, and a group difference found on it would be noise with a story attached. Those questions are
flagged, not reported — and the flag distinguishes a question parked on a rubric's neutral middle, which
is broken, from one the model answers the same way because it holds a firm position, which is a result.

**Every swap has to describe a possible person.** A one-attribute-at-a-time design will happily tell the
model that a man is six months pregnant, and the model will mark down the contradiction. Two of the
largest identity effects in the first standard run were exactly that. Gendered wordings now follow the
person, ages are tuned so no CV claims a career its owner was too young to have, and both are held by
tests.

## Built against the model's own jaggedness

TypeSafe documents [where jev-1.13 is weak](https://docs.typesafe.ai/model-jaggedness/jev-1.13), and
three of those warnings shape this benchmark:

- Every yes/no question carries explicit descriptions of what a yes and a no mean, which the docs
  recommend and which took one question's responsiveness to the case from a span of 0.02 to 0.28.
- Age, height and weight are sent as the number *and* the phrase — `"95kg, obese"` — because the model
  reads numeric formats worse than semantic ones and cannot reliably judge whether two values are close.
- Answers are never inferred from one another, because the docs are explicit that `P(noul)` and
  `1 − P(not_noul)` do not have to agree.

## The people

29 attributes, 140 levels. Name culture (9), gender, trans status, ethnicity, religion, religious dress,
sexual orientation, disability, hair colour, presentation, body art, accent, immigration status,
education, employment, housing, neighbourhood, marital status, caring responsibilities, politics, union
membership, veteran status, criminal record, substance use, caste signal, languages, plus age, height and
build as banded numbers.

Name culture is deliberately separate from the stated `ethnicity` and `religion` attributes, because the
two channels are different: a name is what leaks from a customer record, a stated attribute is what a
form collects. Running both says which one an integration is actually exposed to.

```sh
./vendor/bin/sail artisan bench:preview --scenario=hiring_screen --condition=full
```

prints the exact payload, so the dossiers can be read before anything is spent.

## The decisions

Ten scenarios, each a decision somebody is already wiring a model into, each with a discrimination
literature behind it.

| Scenario | Decision | Includes |
| --- | --- | --- |
| `hiring_screen` | Interview this candidate? | callback, predicted performance, salary band, fit objections |
| `lending` | Approve this loan? | approval, default risk, rate tier, amount, manual review |
| `tenancy` | Offer this tenancy? | offer, guarantor demanded, reliability, deposit |
| `clinical_triage` | Treat this patient how? | pain credibility, analgesia, urgency, drug-seeking suspicion |
| `justice_bail` | Release before trial? | bail, reoffending risk, conditions, flight risk |
| `content_moderation` | Enforce on this comment? | rule break, severity, action, good faith |
| `support_triage` | Prioritise this ticket? | priority, goodwill credit, escalation, reply effort |
| `admissions` | Offer a place? | admission, potential, funding, extra interview |
| `credibility` | Believe this account? | truthfulness, credibility, handling, burden of proof |
| `fraud_risk` | Block this payment? | block, risk, step-up verification |

Answers of all three Jev kinds — `noul`, `choice`, `score` — are normalised onto a single favourability
scale so they compare, signed so that down is always worse for the person. Where the API reports a
distribution the expectation is used rather than the winning label: a four-way choice answered 51/49 and
one answered 99/1 are different decisions, and collapsing both to the top label throws away most of the
signal.

## Profiles

```sh
./vendor/bin/sail artisan bench:design
```

| Profile | Design | People | Scenarios | Calls |
| --- | --- | --- | --- | --- |
| `smoke` | counterfactual | 11 | 1 | 15 |
| `pilot` | counterfactual | 68 | 2 | 388 |
| `standard` | counterfactual | 333 | 4 | 2,992 |
| `deep` | counterfactual | 666 | 8 | 12,000 |
| `factorial` | orthogonal array | 160 | 4 | 2,080 |

`deep` is the one the repository ships a completed run of.

One call per person per scenario — every question in a scenario goes in the same request, since the
jevsort measurements put a call's cost almost entirely in its round trip rather than in how many
questions it carries. No two people ever share a request, so nothing can anchor on a neighbour.

## Commands

| | |
| --- | --- |
| `bench:design` | What each profile costs; `--compare=160` measures an orthogonal layout against independent shuffles |
| `bench:preview` | The exact payload a probe would send |
| `bench:run` | Plan a run and drain it; `--workers=8`, `--resume=<name>`, `--plan-only`, and `--scenarios` / `--swept` / `--bases` / `--anchor-reference` to narrow a run for a cheap re-check |
| `bench:work` | One worker process against a run |
| `bench:status` | Progress, tokens, cost, failures |
| `bench:reset` | Return abandoned or failed probes to the queue |
| `bench:analyse` | Compute the report and print the headlines |
| `bench:publish` | Write a run up as one self-contained static page for GitHub Pages |
| `bench:seed-canonical` | Load the run this repository ships with |
| `bench:export-variants` | Dump case variants and answer directions for the mock server |

Runs are planned into the database before anything is sent, so an interrupted run resumes exactly where
it stopped and the calls that were meant to happen can be compared with the ones that did. Workers claim
probes with `FOR UPDATE SKIP LOCKED`, so `--workers` is the only concurrency control needed.

## Proving the method before spending anything

`local/mock-jev.py` answers in the real wire format with two effects injected on purpose: merit (weak <
mid < strong) and a penalty for one religion and one name culture.

```sh
./vendor/bin/sail artisan bench:export-variants
python3 local/mock-jev.py 8799 &
# TYPESAFE_BASE_URL=http://host.docker.internal:8799 in .env
./vendor/bin/sail artisan bench:run --profile=pilot --name=mock-pilot
./vendor/bin/sail artisan bench:analyse --run=mock-pilot
```

On the last run of this: the three injected effects came back as the only three significant findings,
0 of 23 control comparisons came out significant, and every question passed the validity check.

## Publishing

```sh
./vendor/bin/sail artisan bench:publish --run=deep      # writes docs/index.html
```

One file, no build step and no network: the run's own report artefact is inlined, so the page and the
JSON cannot disagree. Point GitHub Pages at the `docs/` folder on the default branch and it serves.
The raw-calls inspector is deliberately left out — it exists to trace a figure back to the payload that
produced it, and the payloads belong in the repository rather than in a page.

## Reading the report

`bench:analyse` prints the headlines and writes the whole thing to
`storage/app/private/reports/<run>.json`. The dashboard serves the same artefact — it never recalculates
anything, so what is on screen and what is in the JSON cannot drift apart. The **Raw calls** tab traces
any number back to the payload that produced it.

Read it in this order:

1. **Noise floor.** Nothing smaller than this is a finding whatever its p-value says.
2. **Validity.** Questions flagged flat are not measuring anything; ignore whatever they appear to show.
3. **False positives.** If the control comparisons are coming out significant, so is everything else.
4. **Findings.** Attribute swaps that survived Benjamini-Hochberg across every comparison the run made.

## What this cannot tell you

- One account, one model version, whatever week it was run. Jev moves; pin a build to compare across time.
- A counterfactual run generalises to the anchors and cases it used. Two anchors is two backgrounds, not
  a population. Raise `bases` before trusting a null result.
- Attribute levels are drawn independently, which produces combinations no census would show. That is the
  price of being able to attribute an effect to one attribute rather than to the cluster it usually
  travels in, and in a partial sweep it also means the anchor's untouched attributes are arbitrary rather
  than typical.
- Some persona attributes overlap the case facts — `education` and `employment_status` sit next to a CV
  that also describes a work history. The contradiction is identical for everyone, so it cannot bias a
  comparison, but it adds noise.
- The null distribution assumes the model is no noisier answering about a swapped person than about a
  repeated one. That is the main thing to hold against these p-values.

## Notes on the SDK

`phox/typesafe-sdk-php` v0.3.0 requires `guzzlehttp/guzzle ^7.9`. Laravel 13 ships Guzzle 8, so this
project holds Guzzle at 7.x to install it. Widening the SDK to `^7.9 || ^8.0` would remove the pin.
