import { expect, test } from 'bun:test';
import { withBuildLock } from '../../scripts/build-lib';

test('concurrent frontend builds do not update shared output at the same time', async () => {
  let running = 0;
  let maximum = 0;
  await Promise.all(Array.from({ length: 3 }, () => withBuildLock(async () => {
    running++;
    maximum = Math.max(maximum, running);
    await Bun.sleep(20);
    running--;
  })));
  expect(maximum).toBe(1);
});

test('a failed build releases the lock for the next build', async () => {
  await expect(withBuildLock(async () => {
    throw new Error('test build failure');
  })).rejects.toThrow('test build failure');
  expect(await withBuildLock(async () => 'next build')).toBe('next build');
});
