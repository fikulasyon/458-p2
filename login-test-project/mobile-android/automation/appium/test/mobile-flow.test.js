import test from "node:test";
import assert from "node:assert/strict";
import {
  answer,
  assertCurrentQuestion,
  complete,
  login,
  startSurvey,
  syncRunnerState,
} from "../src/core/mobile-flow.js";
import { byTag } from "../src/core/selectors.js";
import {
  runnerCurrentQuestionTag,
  runnerMultipleChoiceOptionTag,
  runnerRatingChipTag,
  surveyStartButtonTag,
  Tags,
} from "../src/core/tags.js";

class MockElement {
  constructor(tag) {
    this.tag = tag;
    this.calls = [];
    this.displayed = true;
    this.text = "";
  }

  async waitForDisplayed(options) {
    this.calls.push(["waitForDisplayed", options]);
  }

  async click() {
    this.calls.push(["click"]);
  }

  async setValue(value) {
    this.calls.push(["setValue", value]);
  }

  async isDisplayed() {
    this.calls.push(["isDisplayed"]);
    return this.displayed;
  }

  async isExisting() {
    this.calls.push(["isExisting"]);
    return this.displayed;
  }

  async getText() {
    this.calls.push(["getText"]);
    return this.text;
  }
}

function createMockDriver(tags) {
  const elements = new Map(tags.map((tag) => [byTag(tag), new MockElement(tag)]));
  const selectors = [];

  return {
    selectors,
    elements,
    async $(selector) {
      selectors.push(selector);
      if (!elements.has(selector)) {
        elements.set(selector, new MockElement(selector.replace(/^id=/, "")));
      }
      return elements.get(selector);
    },
  };
}

test("login fills credentials and submits", async () => {
  const driver = createMockDriver([
    Tags.SCREEN_LOGIN,
    Tags.LOGIN_EMAIL_INPUT,
    Tags.LOGIN_PASSWORD_INPUT,
    Tags.LOGIN_SUBMIT_BUTTON,
    Tags.SCREEN_SURVEY_LIST,
  ]);

  await login(driver, { email: "u@example.com", password: "secret" });

  const email = driver.elements.get(byTag(Tags.LOGIN_EMAIL_INPUT));
  const password = driver.elements.get(byTag(Tags.LOGIN_PASSWORD_INPUT));
  const submit = driver.elements.get(byTag(Tags.LOGIN_SUBMIT_BUTTON));
  assert.deepEqual(email.calls.map((entry) => entry[0]), ["isExisting", "isDisplayed", "click", "setValue"]);
  assert.equal(email.calls[3][1], "u@example.com");
  assert.deepEqual(password.calls.map((entry) => entry[0]), ["isExisting", "isDisplayed", "click", "setValue"]);
  assert.equal(password.calls[3][1], "secret");
  assert.deepEqual(submit.calls.map((entry) => entry[0]), ["isExisting", "isDisplayed", "click"]);
});

test("startSurvey uses survey-specific start button tag", async () => {
  const startTag = surveyStartButtonTag(42);
  const driver = createMockDriver([Tags.SCREEN_SURVEY_LIST, startTag, Tags.SCREEN_SURVEY_RUNNER]);

  await startSurvey(driver, { surveyId: 42 });

  assert.ok(driver.selectors.includes(byTag(startTag)));
});

test("answer multiple choice uses question+option tag", async () => {
  const optionTag = runnerMultipleChoiceOptionTag("q2", "b");
  const driver = createMockDriver([optionTag]);

  await answer(driver, {
    type: "multiple_choice",
    questionStableKey: "q2",
    optionValue: "b",
  });

  const option = driver.elements.get(byTag(optionTag));
  assert.deepEqual(option.calls.map((entry) => entry[0]), ["isExisting", "isDisplayed", "click"]);
});

test("answer rating selects chip and submits", async () => {
  const chipTag = runnerRatingChipTag(4);
  const driver = createMockDriver([chipTag, Tags.RUNNER_RATING_SUBMIT_BUTTON]);

  await answer(driver, {
    type: "rating",
    value: 4,
  });

  const chip = driver.elements.get(byTag(chipTag));
  const submit = driver.elements.get(byTag(Tags.RUNNER_RATING_SUBMIT_BUTTON));
  assert.deepEqual(chip.calls.map((entry) => entry[0]), ["isExisting", "isDisplayed", "click"]);
  assert.deepEqual(submit.calls.map((entry) => entry[0]), ["isExisting", "isDisplayed", "click"]);
});

test("answer open ended types text and submits", async () => {
  const driver = createMockDriver([Tags.RUNNER_OPEN_ENDED_INPUT, Tags.RUNNER_OPEN_ENDED_SUBMIT_BUTTON]);

  await answer(driver, {
    type: "open_ended",
    text: "hello world",
  });

  const input = driver.elements.get(byTag(Tags.RUNNER_OPEN_ENDED_INPUT));
  const submit = driver.elements.get(byTag(Tags.RUNNER_OPEN_ENDED_SUBMIT_BUTTON));
  assert.deepEqual(input.calls.map((entry) => entry[0]), ["isExisting", "isDisplayed", "click", "setValue"]);
  assert.equal(input.calls[3][1], "hello world");
  assert.deepEqual(submit.calls.map((entry) => entry[0]), ["isExisting", "isDisplayed", "click"]);
});

test("assertCurrentQuestion waits for stable-key tag", async () => {
  const tag = runnerCurrentQuestionTag("q7");
  const driver = createMockDriver([Tags.SCREEN_SURVEY_RUNNER, tag]);

  await assertCurrentQuestion(driver, { stableKey: "q7" });

  assert.ok(driver.selectors.includes(byTag(tag)));
});

test("complete clicks complete button and waits completion screen", async () => {
  const driver = createMockDriver([Tags.RUNNER_COMPLETE_BUTTON, Tags.SCREEN_COMPLETION]);

  await complete(driver);

  const completeButton = driver.elements.get(byTag(Tags.RUNNER_COMPLETE_BUTTON));
  assert.deepEqual(completeButton.calls.map((entry) => entry[0]), ["isExisting", "isDisplayed", "click"]);
});

test("syncRunnerState clicks sync and waits runner screen", async () => {
  const driver = createMockDriver([Tags.RUNNER_SYNC_BUTTON, Tags.SCREEN_SURVEY_RUNNER]);

  await syncRunnerState(driver);

  const syncButton = driver.elements.get(byTag(Tags.RUNNER_SYNC_BUTTON));
  assert.deepEqual(syncButton.calls.map((entry) => entry[0]), ["isExisting", "isDisplayed", "click"]);
});

test("answer throws for unsupported type", async () => {
  const driver = createMockDriver([]);
  await assert.rejects(
    async () => {
      await answer(driver, { type: "unknown" });
    },
    /Unsupported answer type/,
  );
});
