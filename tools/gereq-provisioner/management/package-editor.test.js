import {test,expect} from 'bun:test';
import {parsePrice,formatPrice} from './package-editor.js';
test('prices use exact minor units and preserve zero versus unspecified',()=>{
 for(const [text,minor] of [['',null],['0',0],['1290',129000],['1290,90',129090],['1290.01',129001],['0001.2',120]])expect(parsePrice(text)).toBe(minor);
 for(const value of [null,0,1,99,129090,9007199254740991])expect(parsePrice(formatPrice(value))).toBe(value);
});
test('prices reject negative, rounded, exponential and unsafe input',()=>{
 for(const text of ['-1','1.999','1e3','NaN','Infinity','1,2,3','90071992547410'])expect(()=>parsePrice(text)).toThrow();
});
