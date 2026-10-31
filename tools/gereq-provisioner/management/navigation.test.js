import {test,expect} from 'bun:test';
import {parseTabHash,tabHash} from './navigation.js';
test('existing bookmarks open the intended tab or customer instance',()=>{
 expect(parseTabHash('#package-editor').tab).toBe('packages');
 expect(parseTabHash('#release-panel').tab).toBe('releases');
 expect(parseTabHash('#package-modules-test')).toEqual({tab:'instances',site:'package-modules-test',detail:'overview'});
});
test('instance detail tabs round trip and reject malformed state',()=>{
 expect(parseTabHash(tabHash('instances','package-modules-test','billing'))).toEqual({tab:'instances',site:'package-modules-test',detail:'billing'});
 expect(parseTabHash('#instances/customer-one/unknown').detail).toBe('overview');
 expect(parseTabHash('#%broken')).toEqual({tab:'instances',site:'',detail:'overview'});
});

test('customer view is a separate tab',()=>{expect(parseTabHash('#customer-view')).toEqual({tab:'customer-view',site:'',detail:'overview'});});

test('domains is a separate management tab',()=>{expect(parseTabHash('#domains')).toEqual({tab:'domains',site:'',detail:'overview'});});
