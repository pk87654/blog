import {
    emotionList
} from './emotionList.js';

export function processEmotion(str) {
    return str.replace(/#[\u4E00-\u9FA5]{1,3};/gi, (words) => {
        let word = words.replace(/#|;/gi, '')
        let index = emotionList.indexOf(word)
        if (index !== -1) {
            return `<img src="https://api.lpyhutu.cn/HtBlog/public/upload/img/emotion/${index}.gif" align="middle">`
        } else {
            return words
        }
    })
}