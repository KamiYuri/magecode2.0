// SIM's Dolos reporter.
//
// Why this exists: the `dolos` CLI's CSV export carries similarity, overlap and
// longest-fragment per pair but no fragment coordinates, and `a_regions` /
// `b_regions` in `result.analysis.v1` are exactly those coordinates. The
// library does expose them — `pair.buildFragments()` yields leftSelection and
// rightSelection with startRow/startCol/endRow/endCol — so SIM drives the
// library through this script instead of the CLI.
//
// Contract with the Go side (internal/dolos):
//   argv:   --language <python|java|c|cpp> --dir <workspace>
//   stdout: one JSON document, described by reportVersion below
//   stderr: human-readable diagnostics only
//   exit:   0 on success, 1 on failure
//
// Coordinates are emitted exactly as Dolos produces them — 0-based rows and
// columns. Converting to the editor's 1-based convention is the Go side's job,
// so the translation lives in one place with a test on it.
import { readdir } from "node:fs/promises";
import { join } from "node:path";
import { Dolos } from "@dodona/dolos-lib";

const reportVersion = "1.0";

function parseArgs(argv) {
  const args = { language: null, dir: null };
  for (let i = 0; i < argv.length; i += 2) {
    const [flag, value] = [argv[i], argv[i + 1]];
    if (flag === "--language") args.language = value;
    else if (flag === "--dir") args.dir = value;
    else throw new Error(`unknown argument ${flag}`);
  }
  if (!args.language) throw new Error("--language is required");
  if (!args.dir) throw new Error("--dir is required");
  return args;
}

function selection(s) {
  return { startRow: s.startRow, startCol: s.startCol, endRow: s.endRow, endCol: s.endCol };
}

async function main() {
  const { language, dir } = parseArgs(process.argv.slice(2));

  const entries = await readdir(dir, { withFileTypes: true });
  const files = entries
    .filter((entry) => entry.isFile())
    .map((entry) => join(dir, entry.name))
    .sort();

  if (files.length < 2) {
    throw new Error(`${dir} holds ${files.length} file(s); at least 2 are needed to compare`);
  }

  const dolos = new Dolos({ language });
  const report = await dolos.analyzePaths(files);

  const pairs = [];
  for (const pair of report.allPairs()) {
    // Dolos reports every combination, including the ones with nothing in
    // common. Those are not matches and api would store them as rows of
    // 0.0 — for a 150-submission group that is eleven thousand empty rows.
    if (pair.overlap <= 0) continue;

    pairs.push({
      leftPath: pair.leftFile.path,
      rightPath: pair.rightFile.path,
      similarity: pair.similarity,
      // The library's names differ from the CSV export's; these are the
      // CSV/schema names, so the Go side reads what the schema calls them.
      longestFragment: pair.longest,
      totalOverlap: pair.overlap,
      fragments: pair.buildFragments().map((fragment) => ({
        left: selection(fragment.leftSelection),
        right: selection(fragment.rightSelection),
      })),
    });
  }

  process.stdout.write(
    JSON.stringify({
      version: reportVersion,
      language,
      files: files.map((path) => ({ path })),
      pairs,
    })
  );
}

main().catch((error) => {
  process.stderr.write(`${error?.stack ?? error}\n`);
  process.exit(1);
});
