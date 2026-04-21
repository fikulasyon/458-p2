import {
  assertCurrentQuestion,
  complete,
  login,
  startSurvey,
  waitForSurveyListScreen,
} from "../core/mobile-flow.js";
import { createDriverSession } from "../runtime/create-driver-session.js";

async function run() {
  const surveyId = Number.parseInt(process.env.SMOKE_SURVEY_ID ?? "1", 10);
  const email = process.env.MOBILE_EMAIL ?? "t1@g.com";
  const password = process.env.MOBILE_PASSWORD ?? "123123123";
  const expectedQuestion = process.env.SMOKE_EXPECTED_QUESTION_STABLE_KEY;
  const shouldComplete = (process.env.SMOKE_COMPLETE ?? "false") === "true";

  const driver = await createDriverSession();
  try {
    await login(driver, { email, password });
    await waitForSurveyListScreen(driver);
    await startSurvey(driver, { surveyId });
    if (expectedQuestion) {
      await assertCurrentQuestion(driver, { stableKey: expectedQuestion });
    }
    if (shouldComplete) {
      await complete(driver);
    }
    console.log("Appium smoke flow completed.");
  } finally {
    await driver.deleteSession();
  }
}

run().catch((error) => {
  console.error(error);
  process.exit(1);
});
