function countLines(text) {
  if (!text) {
    return 0;
  }
  return text.split('\n').length;
}

/**
 * Heuristic: large one-off changes are more likely AI (Cursor apply / agent).
 * @param {{ linesAdded: number, linesRemoved: number, changeCount: number, totalChars: number, reason?: number }} p
 * @returns {'ai'|'user'}
 */
function classifyEdit(p) {
  const churn = (p.linesAdded || 0) + (p.linesRemoved || 0);
  const chars = p.totalChars || 0;

  // Undo/Redo reason in VS Code: 1=Undo, 2=Redo — treat as user
  if (p.reason === 1 || p.reason === 2) {
    return 'user';
  }

  if (churn >= 25 || chars >= 1200) {
    return 'ai';
  }
  if (p.changeCount === 1 && churn >= 12) {
    return 'ai';
  }
  if (p.changeCount === 1 && chars >= 600 && churn >= 5) {
    return 'ai';
  }
  return 'user';
}

module.exports = { countLines, classifyEdit };
