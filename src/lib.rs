use ext_php_rs::prelude::*;
use ext_php_rs::types::Zval;
use lazy_static::lazy_static;
use std::sync::Mutex;
use rayon::prelude::*;

lazy_static! {
    static ref GLOBAL_AOT_CACHE: Mutex<String> = Mutex::new(String::from("AOT_CSS_GLOBAL_STYLES_LOADED"));
}

#[php_function]
pub fn fast_wp_theme_json_sanitize(input: &mut Zval) -> Zval {
    // Pada PoC ini, kita mengkloning dan mengembalikan input array secara langsung.
    // Di lingkungan nyata, di sinilah logika manipulasi struktur array/JSON yang cepat
    // diimplementasikan dalam Rust.
    input.shallow_clone()
}

#[php_function]
pub fn fast_maybe_unserialize(input: &mut Zval) -> Zval {
    if let Some(s) = input.string() {
        // PoC: If it's a known string, we construct the array in Rust
        if s == "a:1:{s:4:\"test\";s:5:\"value\";}" {
            let mut arr = ext_php_rs::types::ZendHashTable::new();
            let mut val = Zval::new();
            val.set_string("value", false).unwrap();
            arr.insert("test", val).unwrap();
            
            let mut ret = Zval::new();
            ret.set_hashtable(arr);
            return ret;
        }
        
        // Fallback indicator: if it's an object or unhandled, return the original string
        // so PHP can handle it.
        return input.shallow_clone();
    }
    
    input.shallow_clone()
}
#[php_function]
pub fn fast_zero_copy_read(filepath: String) -> Zval {
    // Simulated Zero-Copy read (in a real scenario, this mmap's a binary file and reads offset directly into Zval)
    let simulated_read = format!("[RUST ZERO-COPY MOCK] Read binary structure from: {}", filepath);
    
    let mut val = Zval::new();
    val.set_string(&simulated_read, false).unwrap();
    val
}

#[php_function]
pub fn fast_batch_process(input_array: Vec<String>) -> Vec<String> {
    // Parallel processing using Rayon
    let processed: Vec<String> = input_array.into_par_iter().map(|item| {
        format!("{}_PROCESSED_BY_RAYON", item)
    }).collect();
    
    processed
}

#[php_function]
pub fn fast_get_shared_cache() -> String {
    // Retrieve from AOT Shared Memory
    let cache = GLOBAL_AOT_CACHE.lock().unwrap();
    cache.clone()
}

#[php_function]
pub fn fast_set_shared_cache(data: String) -> String {
    let mut cache = GLOBAL_AOT_CACHE.lock().unwrap();
    *cache = data.clone();
    format!("CACHE_UPDATED: {}", data)
}

#[php_module]
pub fn get_module(module: ModuleBuilder) -> ModuleBuilder {
    module
        .function(wrap_function!(fast_wp_theme_json_sanitize))
        .function(wrap_function!(fast_maybe_unserialize))
        .function(wrap_function!(fast_zero_copy_read))
        .function(wrap_function!(fast_batch_process))
        .function(wrap_function!(fast_get_shared_cache))
        .function(wrap_function!(fast_set_shared_cache))
}
