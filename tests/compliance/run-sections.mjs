// A fatal section must remain a failed run even if all earlier assertions passed.
export async function runSections(sections, browser) {
  if (!sections.length) throw new Error('No compliance sections selected');
  const completed = [];
  for (const [key, run] of sections) {
    try {
      await run(browser);
      completed.push(key);
    } catch (error) {
      return { completed, failed: key, error, notRun: sections.slice(completed.length + 1).map(([name]) => name) };
    }
  }
  return { completed, failed: null, notRun: [] };
}
